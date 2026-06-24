<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\SDM\Models\PeriodePenggajian;
use App\Modules\SDM\Models\SlipGaji;
use App\Modules\SDM\Services\AttendanceImportService;
use App\Modules\SDM\Services\PayrollCalculationService;
use App\Modules\SDM\Services\PeriodePenggajianService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class PeriodePenggajianController extends Controller
{
    use \App\Http\Controllers\Concerns\InlinesPrintAssets;

    public function index()
    {
        $periodes = PeriodePenggajian::orderByDesc('tahun')->orderByDesc('bulan')->get();

        $totals = SlipGaji::selectRaw('periode_id, SUM(total_gaji) as total')
            ->groupBy('periode_id')->pluck('total', 'periode_id');

        return view('erp.sdm.periode-gaji.index', compact('periodes', 'totals'));
    }

    public function create()
    {
        return view('erp.sdm.periode-gaji.create');
    }

    /** Halaman Pengaturan Periode (tab Absensi): info auto-create + tombol manual + daftar periode. */
    public function settings()
    {
        $periodes = PeriodePenggajian::orderByDesc('tahun')->orderByDesc('bulan')->get();

        $now           = Carbon::now();
        $currentLabel  = $now->translatedFormat('F Y');
        $currentExists = $periodes->contains(fn ($p) => (int) $p->bulan === $now->month && (int) $p->tahun === $now->year);

        return view('erp.sdm.periode-gaji.settings', compact('periodes', 'currentLabel', 'currentExists'));
    }

    /** Trigger manual: buat periode bulan berjalan (logika sama dengan cron periode-gaji:ensure-current). */
    public function ensureCurrentMonth(PeriodePenggajianService $service)
    {
        $now     = Carbon::now();
        $periode = $service->ensureForMonth((int) $now->month, (int) $now->year);

        return back()->with('success', "Periode {$periode->label} siap (status: {$periode->status}).");
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'bulan' => 'required|integer|min:1|max:12',
            'tahun' => 'required|integer|min:2020|max:2100',
            'notes' => 'nullable|string',
        ]);

        $bulan = (int) $data['bulan'];
        $tahun = (int) $data['tahun'];

        if (PeriodePenggajian::where('bulan', $bulan)->where('tahun', $tahun)->exists()) {
            return back()->withErrors(['bulan' => 'Periode untuk bulan tersebut sudah ada.'])->withInput();
        }

        $code = sprintf('PG-%04d%02d', $tahun, $bulan);
        $startDate = Carbon::create($tahun, $bulan, 1);
        $endDate = (clone $startDate)->endOfMonth();
        $label = $startDate->translatedFormat('F Y');

        $periode = PeriodePenggajian::create([
            'code'       => $code,
            'bulan'      => $bulan,
            'tahun'      => $tahun,
            'label'      => $label,
            'start_date' => $startDate->toDateString(),
            'end_date'   => $endDate->toDateString(),
            'status'     => 'open',
            'notes'      => $data['notes'] ?? null,
        ]);

        return redirect()->route('sdm.periode-gaji.show', $periode->id)
            ->with('success', "Periode {$label} dibuat.");
    }

    public function openOrCreate(Request $request)
    {
        $data = $request->validate([
            'bulan' => 'required|integer|min:1|max:12',
            'tahun' => 'required|integer|min:2020|max:2100',
        ]);

        $bulan = (int) $data['bulan'];
        $tahun = (int) $data['tahun'];

        $periode = app(PeriodePenggajianService::class)->ensureForMonth($bulan, $tahun);

        return redirect()->route('sdm.periode-gaji.show', $periode->id);
    }

    public function show(int $id)
    {
        $periode = PeriodePenggajian::findOrFail($id);
        $slips = SlipGaji::with(['karyawan.department'])
            ->whereHas('karyawan', fn ($q) => $q->where('is_active', true))
            ->where('periode_id', $id)
            ->get()
            ->sortBy(fn($s) => $s->karyawan->staf_code ?? '');

        $cashAccounts = \App\Core\Accounting\Account::whereIn('account_category', ['cash', 'cash_equivalent'])
            ->where('is_active', 1)->orderBy('code')->get(['id', 'code', 'name']);

        // Cek karyawan yang sudah punya SalaryPayment di periode ini (untuk hide tombol Bayar)
        $paidKaryawanIds = \App\Modules\SDM\Models\SalaryPayment::where('periode_bulan', $periode->bulan)
            ->where('periode_tahun', $periode->tahun)
            ->whereIn('status', ['draft', 'posted'])
            ->pluck('karyawan_id')->all();

        $allPeriodes = PeriodePenggajian::orderByDesc('tahun')->orderByDesc('bulan')
            ->get(['id', 'code', 'label', 'bulan', 'tahun', 'status']);

        return view('erp.sdm.periode-gaji.show', compact('periode', 'slips', 'cashAccounts', 'paidKaryawanIds', 'allPeriodes'));
    }

    public function uploadExcel(Request $request, int $id, AttendanceImportService $service)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $periode = PeriodePenggajian::findOrFail($id);
        if (! $periode->canImport()) {
            return back()->with('error', 'Periode sudah finalized atau void, tidak bisa upload.');
        }

        $result = $service->import($request->file('file'), (int) $periode->bulan, (int) $periode->tahun);

        $msg = "Import selesai: {$result['imported']} baris, {$result['skipped']} dilewati.";
        if (! empty($result['errors'])) {
            $msg .= ' Beberapa error: ' . implode(' | ', array_slice($result['errors'], 0, 5));
        }

        return back()->with('success', $msg);
    }

    /** Generate/refresh slip gaji semua karyawan dari data absensi periode (tanpa Excel). */
    public function generateSlips(int $id, PayrollCalculationService $payroll)
    {
        $periode = PeriodePenggajian::findOrFail($id);

        if (! $periode->isOpen()) {
            return back()->with('error', "Slip hanya bisa di-generate untuk periode berjalan (status sekarang: {$periode->status_label}).");
        }

        $count = \App\Modules\SDM\Models\Attendance::where('periode_id', $periode->id)
            ->distinct()->count('karyawan_id');

        if ($count === 0) {
            return back()->with('error', 'Belum ada data absensi di periode ini — slip belum bisa dibuat.');
        }

        $payroll->generateAllSlips($periode);

        return back()->with('success', "Slip gaji digenerate dari absensi untuk {$count} karyawan.");
    }

    public function finalize(int $id, PayrollCalculationService $payroll)
    {
        $periode = PeriodePenggajian::findOrFail($id);
        if (! $periode->canBeFinalized()) {
            return back()->with('error', 'Periode tidak bisa difinalisasi (status saat ini: ' . $periode->status . ').');
        }
        $payroll->finalizePeriode($periode);
        return back()->with('success', 'Periode difinalisasi.');
    }

    public function void(Request $request, int $id)
    {
        $periode = PeriodePenggajian::findOrFail($id);
        if (! $periode->canBeVoided()) {
            return back()->with('error', 'Periode tidak bisa di-void.');
        }
        $periode->update([
            'status'      => 'void',
            'voided_at'   => now(),
            'void_reason' => $request->input('void_reason'),
        ]);
        return back()->with('success', 'Periode di-void.');
    }

    public function destroy(int $id)
    {
        $periode = PeriodePenggajian::findOrFail($id);
        if (in_array($periode->status, ['finalized'], true)) {
            return back()->with('error', 'Periode finalized tidak bisa dihapus.');
        }
        $periode->delete();
        return back()->with('success', 'Periode dihapus.');
    }

    public function printAll(int $id, Request $request)
    {
        $periode = PeriodePenggajian::findOrFail($id);

        $q = SlipGaji::with(['karyawan.department', 'periode', 'komponen'])
            ->whereHas('karyawan', fn ($qq) => $qq->where('is_active', true))
            ->where('periode_id', $id);

        $ids = $request->input('ids');
        if (! empty($ids)) {
            $ids = is_array($ids) ? $ids : explode(',', $ids);
            $ids = array_filter(array_map('intval', $ids));
            if (! empty($ids)) $q->whereIn('id', $ids);
        }

        $slips = $q->get()->sortBy(fn($s) => $s->karyawan->staf_code ?? '');

        if ($request->boolean('pdf')) {
            return $this->renderSlipsPdf($periode, $slips, 'slip-periode-' . $periode->code);
        }

        return view('erp.sdm.slip-gaji.print-all', compact('periode', 'slips'));
    }

    protected function renderSlipsPdf(PeriodePenggajian $periode, $slips, string $filename)
    {
        $attendancesByKaryawan = \App\Modules\SDM\Models\Attendance::where('periode_id', $periode->id)
            ->whereIn('karyawan_id', $slips->pluck('karyawan_id'))
            ->orderBy('tanggal')
            ->get()
            ->groupBy('karyawan_id');

        $html = view('erp.sdm.slip-gaji.print-all', [
            'periode'              => $periode,
            'slips'                => $slips,
            'pdfMode'              => true,
            'attendancesByKaryawan'=> $attendancesByKaryawan,
        ])->render();

        $html = $this->inlineLocalAssets($html);

        $bs = \Spatie\Browsershot\Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(8, 8, 8, 8)
            ->emulateMedia('print')
            ->timeout(120);

        if ($chromePath = env('BROWSERSHOT_CHROME_PATH')) {
            $bs->setChromePath($chromePath);
        }

        return response($bs->pdf(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '.pdf"',
        ]);
    }
}
