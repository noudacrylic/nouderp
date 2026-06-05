<?php

namespace App\Modules\Finance\Controllers;

use App\Core\Accounting\Account;
use App\Http\Controllers\Controller;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\Kasbon;
use App\Modules\SDM\Models\PayrollSetting;
use App\Modules\SDM\Models\SalaryPayment;
use App\Modules\SDM\Services\SalaryPaymentService;
use Illuminate\Http\Request;

class SalaryPaymentController extends Controller
{
    public function __construct(protected SalaryPaymentService $service) {}

    public function index(Request $request)
    {
        $items = SalaryPayment::with(['karyawan', 'cashAccount'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->date_to, fn($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->periode_tahun, fn($q, $t) => $q->where('periode_tahun', $t))
            ->when($request->periode_bulan, fn($q, $b) => $q->where('periode_bulan', $b))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('number', 'like', "%{$term}%")
                      ->orWhereHas('karyawan', fn($k) => $k->where('name', 'like', "%{$term}%")
                                                          ->orWhere('staf_code', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('erp.finance.salary-payments.index', compact('items'));
    }

    public function create()
    {
        return view('erp.finance.salary-payments.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        try {
            $sp = $this->service->createDraft($data);
            if ($request->input('_after_save') === 'post') {
                $this->service->post($sp);
                return redirect()->route('finance.cash-bank.salary-payments.index')
                    ->with('success', 'Pembayaran Gaji ' . $sp->number . ' dibuat & POSTED.');
            }
            return redirect()->route('finance.cash-bank.salary-payments.index')
                ->with('success', 'Pembayaran Gaji ' . $sp->number . ' dibuat (draft).');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $sp = SalaryPayment::with(['karyawan', 'cashAccount', 'journal'])->findOrFail($id);
        return view('erp.finance.salary-payments.show', compact('sp'));
    }

    public function edit($id)
    {
        $sp = SalaryPayment::with('karyawan')->findOrFail($id);
        if (! $sp->canBeEdited()) {
            return redirect()->route('finance.cash-bank.salary-payments.show', $sp->id)
                ->with('error', 'Hanya draft yang bisa diedit.');
        }
        return view('erp.finance.salary-payments.edit', array_merge($this->formData(), compact('sp')));
    }

    public function update(Request $request, $id)
    {
        $sp = SalaryPayment::findOrFail($id);
        $data = $this->validateData($request);
        try {
            $this->service->update($sp, $data);
            if ($request->input('_after_save') === 'post') {
                $this->service->post($sp->refresh());
                return redirect()->route('finance.cash-bank.salary-payments.index')
                    ->with('success', 'Pembayaran Gaji ' . $sp->number . ' diupdate & POSTED.');
            }
            return redirect()->route('finance.cash-bank.salary-payments.index')
                ->with('success', 'Pembayaran Gaji ' . $sp->number . ' diupdate.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $sp = SalaryPayment::findOrFail($id);
        if (! $sp->isDraft()) {
            return back()->with('error', 'Hanya draft yang bisa dihapus.');
        }
        $sp->delete();
        return back()->with('success', 'Draft dihapus.');
    }

    public function post($id)
    {
        $sp = SalaryPayment::findOrFail($id);
        try {
            $this->service->post($sp);
            return redirect()->route('finance.cash-bank.salary-payments.index')
                ->with('success', 'Pembayaran Gaji ' . $sp->number . ' POSTED.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void(Request $request, $id)
    {
        $sp = SalaryPayment::findOrFail($id);
        try {
            $this->service->void($sp, $request->input('reason'));
            return back()->with('success', 'Pembayaran Gaji ' . $sp->number . ' di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Bulk-create draft SalaryPayment dari Slip Gaji terpilih (dipanggil dari Periode Gaji show).
     */
    public function bulkFromSlips(Request $request)
    {
        $data = $request->validate([
            'slip_ids'        => 'required|array|min:1',
            'slip_ids.*'      => 'integer|exists:sdm_slip_gaji,id',
            'cash_account_id' => 'required|exists:accounts,id',
            'date'            => 'nullable|date',
        ]);

        $date = $data['date'] ?? now()->toDateString();
        $created = 0;
        $skipped = 0;
        $errors  = [];

        $slips = \App\Modules\SDM\Models\SlipGaji::with('periode')->whereIn('id', $data['slip_ids'])->get();

        foreach ($slips as $slip) {
            $periode = $slip->periode;
            if (! $periode) { $skipped++; continue; }

            // Skip kalau sudah ada SalaryPayment aktif untuk karyawan+bulan+tahun ini
            $existing = SalaryPayment::where('karyawan_id', $slip->karyawan_id)
                ->where('periode_bulan', $periode->bulan)
                ->where('periode_tahun', $periode->tahun)
                ->whereIn('status', ['draft', 'posted'])
                ->exists();
            if ($existing) { $skipped++; continue; }

            try {
                $this->service->createDraft([
                    'date'              => $date,
                    'karyawan_id'       => $slip->karyawan_id,
                    'periode_bulan'     => $periode->bulan,
                    'periode_tahun'     => $periode->tahun,
                    'cash_account_id'   => $data['cash_account_id'],
                    // Bruto = subtotal + komponen earning custom. Tanpa komponen, validate()
                    // "Tidak balance" krn nett (=subtotal+komponen-potongan) tak cocok.
                    'bruto_gaji'        => (float) $slip->subtotal + (float) $slip->total_komponen_earning,
                    'bpjs_kes_employee' => (float) $slip->bpjs_kesehatan_amount,
                    'bpjs_tk_employee'  => (float) $slip->bpjs_tk_amount,
                    'pph21_amount'      => (float) $slip->pph21_amount,
                    'kasbon_potongan'   => (float) $slip->kasbon_payment,
                    'nett_dibayar'      => (float) $slip->total_gaji,
                    'notes'             => 'Auto dari Periode Gaji ' . ($periode->code ?? ''),
                ]);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ($slip->karyawan->name ?? 'Slip #' . $slip->id) . ': ' . $e->getMessage();
            }
        }

        $msg = "Pembayaran Gaji: {$created} draft dibuat, {$skipped} dilewati (sudah ada / tidak valid).";
        if (! empty($errors)) {
            $msg .= ' Error: ' . implode(' | ', array_slice($errors, 0, 5));
        }
        return redirect()->route('finance.cash-bank.salary-payments.index')->with('success', $msg);
    }

    /**
     * AJAX: hitung breakdown gaji + potongan untuk karyawan+periode tertentu.
     * Mengambil data dari Summary Absensi (controller dashboard) — direkonstruksi singkat di sini.
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'karyawan_id'   => 'required|exists:sdm_karyawan,id',
            'periode_bulan' => 'required|integer|min:1|max:12',
            'periode_tahun' => 'required|integer|min:2020|max:2100',
        ]);

        return response()->json(
            \App\Modules\SDM\Services\SummaryPreviewService::buildPayrollPreview(
                (int) $data['karyawan_id'],
                (int) $data['periode_bulan'],
                (int) $data['periode_tahun'],
            )
        );
    }

    protected function formData(): array
    {
        $karyawans = Karyawan::active()->orderBy('name')->get(['id', 'staf_code', 'name', 'gaji_pokok', 'ptkp_category', 'ikut_bpjs_kesehatan', 'ikut_bpjs_tk']);
        $cashAccounts = Account::whereIn('account_category', ['cash', 'cash_equivalent'])
            ->where('is_active', 1)->orderBy('code')->get();
        $expenseAccounts = Account::where('type', 'expense')
            ->where('is_active', 1)->orderBy('code')->get();
        $defaultAdminFeeAccountId = Account::where('account_category', 'bank_admin_fee')
            ->where('is_active', 1)->value('id');
        $setting = PayrollSetting::singleton();
        return compact('karyawans', 'cashAccounts', 'expenseAccounts', 'defaultAdminFeeAccountId', 'setting');
    }

    protected function validateData(Request $request): array
    {
        $data = $request->validate([
            'date'                 => 'required|date',
            'karyawan_id'          => 'required|exists:sdm_karyawan,id',
            'periode_bulan'        => 'required|integer|min:1|max:12',
            'periode_tahun'        => 'required|integer|min:2020|max:2100',
            'cash_account_id'      => 'required|exists:accounts,id',
            'bruto_gaji'           => 'nullable|string',
            'bpjs_kes_employee'    => 'nullable|string',
            'bpjs_kes_company'     => 'nullable|string',
            'bpjs_tk_employee'     => 'nullable|string',
            'bpjs_tk_company'      => 'nullable|string',
            'pph21_amount'         => 'nullable|string',
            'kasbon_potongan'     => 'nullable|string',
            'nett_dibayar'         => 'required|string',
            'admin_fee'            => 'nullable|string',
            'admin_fee_account_id' => 'nullable|exists:accounts,id',
            'notes'                => 'nullable|string',
        ]);

        foreach (['bruto_gaji', 'bpjs_kes_employee', 'bpjs_kes_company',
                  'bpjs_tk_employee', 'bpjs_tk_company', 'pph21_amount',
                  'kasbon_potongan', 'nett_dibayar', 'admin_fee'] as $f) {
            $val = $request->input($f);
            $data[$f] = $val !== null && $val !== '' ? (float) clean_number($val) : 0;
        }

        return $data;
    }

    /**
     * Quick-pay dari dashboard absensi: cukup pilih kas + (opsional) biaya admin,
     * sisa komponen di-rekonstruksi dari slip / preview lalu langsung draft+post.
     */
    public function quickPay(Request $request)
    {
        $data = $request->validate([
            'karyawan_id'          => 'required|exists:sdm_karyawan,id',
            'periode_bulan'        => 'required|integer|min:1|max:12',
            'periode_tahun'        => 'required|integer|min:2020|max:2100',
            'cash_account_id'      => 'required|exists:accounts,id',
            'date'                 => 'nullable|date',
            'admin_fee'            => 'nullable|string',
            'admin_fee_account_id' => 'nullable|exists:accounts,id',
            'notes'                => 'nullable|string',
            'redirect_to'          => 'nullable|string',
        ]);

        $adminFee = isset($data['admin_fee']) && $data['admin_fee'] !== ''
            ? (float) clean_number($data['admin_fee']) : 0;

        if ($adminFee > 0 && empty($data['admin_fee_account_id'])) {
            return back()->with('error', 'Akun beban admin bank wajib dipilih saat biaya admin > 0.');
        }

        // Sumber komponen: pakai SlipGaji kalau ada (lebih akurat); fallback ke preview.
        $periode = \App\Modules\SDM\Models\PeriodePenggajian::where('bulan', $data['periode_bulan'])
            ->where('tahun', $data['periode_tahun'])->first();
        $slip = $periode
            ? \App\Modules\SDM\Models\SlipGaji::where('periode_id', $periode->id)
                ->where('karyawan_id', $data['karyawan_id'])->first()
            : null;

        if ($slip) {
            $payload = [
                'date'                 => $data['date'] ?? now()->toDateString(),
                'karyawan_id'          => $data['karyawan_id'],
                'periode_bulan'        => $data['periode_bulan'],
                'periode_tahun'        => $data['periode_tahun'],
                'cash_account_id'      => $data['cash_account_id'],
                // Bruto = subtotal + komponen earning custom (lihat bulkFromSlips).
                'bruto_gaji'           => (float) $slip->subtotal + (float) $slip->total_komponen_earning,
                'bpjs_kes_employee'    => (float) $slip->bpjs_kesehatan_amount,
                'bpjs_tk_employee'     => (float) $slip->bpjs_tk_amount,
                'pph21_amount'         => (float) $slip->pph21_amount,
                'kasbon_potongan'      => (float) $slip->kasbon_payment,
                'nett_dibayar'         => (float) $slip->total_gaji,
                'admin_fee'            => $adminFee,
                'admin_fee_account_id' => $data['admin_fee_account_id'] ?? null,
                'notes'                => $data['notes'] ?? 'Quick pay dari dashboard absensi',
            ];
        } else {
            $preview = \App\Modules\SDM\Services\SummaryPreviewService::buildPayrollPreview(
                (int) $data['karyawan_id'],
                (int) $data['periode_bulan'],
                (int) $data['periode_tahun']
            );
            $payload = [
                'date'                 => $data['date'] ?? now()->toDateString(),
                'karyawan_id'          => $data['karyawan_id'],
                'periode_bulan'        => $data['periode_bulan'],
                'periode_tahun'        => $data['periode_tahun'],
                'cash_account_id'      => $data['cash_account_id'],
                'bruto_gaji'           => $preview['bruto_gaji'],
                'bpjs_kes_employee'    => $preview['bpjs_kes_employee'],
                'bpjs_kes_company'     => $preview['bpjs_kes_company'],
                'bpjs_tk_employee'     => $preview['bpjs_tk_employee'],
                'bpjs_tk_company'      => $preview['bpjs_tk_company'],
                'pph21_amount'         => $preview['pph21_amount'],
                'kasbon_potongan'      => $preview['kasbon_potongan'],
                'nett_dibayar'         => $preview['nett_dibayar'],
                'admin_fee'            => $adminFee,
                'admin_fee_account_id' => $data['admin_fee_account_id'] ?? null,
                'notes'                => $data['notes'] ?? 'Quick pay dari dashboard absensi',
            ];
        }

        try {
            $sp = $this->service->createDraft($payload);
            $this->service->post($sp);
            $msg = 'Pembayaran Gaji ' . $sp->number . ' POSTED.';
            return $data['redirect_to'] ?? false
                ? redirect()->to($data['redirect_to'])->with('success', $msg)
                : back()->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
