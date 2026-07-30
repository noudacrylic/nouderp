<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\CashReceipt;
use App\Modules\Finance\Services\CashReceiptService;
use App\Core\Accounting\Account;
use App\Enums\AccountCodeEnum;
use App\Models\Supplier;
use Illuminate\Http\Request;

class CashReceiptController extends Controller
{
    public function __construct(protected CashReceiptService $service) {}

    public function indexGeneral(Request $request)
    {
        $items = $this->baseIndexQuery($request, 'general')
            ->with(['cashAccount'])
            ->paginate(25)->withQueryString();
        return view('erp.finance.cash-receipts.index-general', compact('items'));
    }

    public function indexRefund(Request $request)
    {
        $items = $this->baseIndexQuery($request, 'supplier_refund')
            ->with(['cashAccount', 'supplier'])
            ->paginate(25)->withQueryString();
        return view('erp.finance.cash-receipts.index-refund', compact('items'));
    }

    protected function baseIndexQuery(Request $request, string $type)
    {
        return CashReceipt::query()
            ->where('type', $type)
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->date_to, fn($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('number', 'like', "%{$term}%")
                      ->orWhere('payer', 'like', "%{$term}%")
                      ->orWhere('reference', 'like', "%{$term}%")
                      ->orWhereHas('lines', fn($l) => $l->where('description', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    public function create(Request $request)
    {
        $type = $request->get('type', 'general');
        return view('erp.finance.cash-receipts.create', $this->formData($type));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        try {
            $cr = $this->service->createDraft($data);
            $action = $request->input('_after_save', '');
            if ($action === 'post') {
                $this->service->post($cr);
                return redirect(list_url($cr->listRouteName()))
                    ->with('success', 'Pemasukan ' . $cr->number . ' dibuat & POSTED.');
            }
            return redirect(list_url($cr->listRouteName()))
                ->with('success', 'Pemasukan ' . $cr->number . ' dibuat sebagai draft.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $cr = CashReceipt::with(['cashAccount', 'supplier', 'lines.account'])->findOrFail($id);
        return view('erp.finance.cash-receipts.show', compact('cr'));
    }

    public function edit($id)
    {
        $cr = CashReceipt::with('lines')->findOrFail($id);
        if (!$cr->isDraft()) {
            return redirect()->route('finance.cash-bank.receipts.show', $cr->id)
                ->with('error', 'Hanya draft yang bisa diedit.');
        }
        return view('erp.finance.cash-receipts.edit', array_merge($this->formData($cr->type, $cr->supplier_id), compact('cr')));
    }

    public function update(Request $request, $id)
    {
        $cr = CashReceipt::findOrFail($id);
        $data = $this->validateData($request);
        try {
            $this->service->update($cr, $data);
            $action = $request->input('_after_save', '');
            if ($action === 'post') {
                $this->service->post($cr->refresh());
                return redirect(list_url($cr->listRouteName()))
                    ->with('success', 'Pemasukan ' . $cr->number . ' diupdate & POSTED.');
            }
            return redirect(list_url($cr->listRouteName()))
                ->with('success', 'Pemasukan ' . $cr->number . ' diupdate (draft).');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $cr = CashReceipt::findOrFail($id);
        if (!$cr->isDraft()) return back()->with('error', 'Hanya draft yang bisa dihapus.');
        $cr->lines()->delete();
        $cr->delete();
        return back()->with('success', 'Draft dihapus.');
    }

    public function post($id)
    {
        $cr = CashReceipt::findOrFail($id);
        try {
            $this->service->post($cr);
            return redirect(list_url($cr->listRouteName()))
                ->with('success', 'Dokumen ' . $cr->number . ' POSTED.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $cr = CashReceipt::findOrFail($id);
        if (!$cr->canBeVoided()) return back()->with('error', 'Tidak bisa di-void.');
        try {
            $this->service->void($cr);
            return back()->with('success', 'Dokumen ' . $cr->number . ' di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function print($id)
    {
        $cr = CashReceipt::with(['cashAccount', 'supplier', 'lines.account'])->findOrFail($id);
        return view('erp.finance.cash-receipts.print', compact('cr'));
    }

    public function supplierOverpayBalance($supplierId)
    {
        return response()->json([
            'balance' => $this->service->getSupplierOverpayBalance((int) $supplierId),
        ]);
    }

    /**
     * Quick-store untuk modal di halaman Rekonsiliasi Bank.
     * Bikin Pemasukan Umum 1-baris, langsung POSTED, return JSON.
     */
    public function quickStore(Request $request)
    {
        $data = $request->validate([
            'date'               => 'required|date',
            'cash_account_id'    => 'required|exists:accounts,id',
            'revenue_account_id' => 'required|exists:accounts,id',
            'amount'             => 'required|numeric|gt:0',
            'description'        => 'nullable|string|max:255',
            'reference'          => 'nullable|string|max:255',
        ]);

        try {
            $cr = $this->service->createDraft([
                'date'            => $data['date'],
                'type'            => 'general',
                'cash_account_id' => $data['cash_account_id'],
                'reference'       => $data['reference'] ?? null,
                // notes dipakai sebagai description cash-line → tampil di rekonsiliasi bank
                'notes'           => $data['description'] ?? null,
                'lines' => [[
                    'account_id'  => $data['revenue_account_id'],
                    'amount'      => $data['amount'],
                    'description' => $data['description'] ?? null,
                ]],
            ]);
            $this->service->post($cr);
            return response()->json([
                'success' => true,
                'number'  => $cr->number,
                'message' => 'Pemasukan ' . $cr->number . ' dibuat & POSTED.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    protected function formData(string $type = 'general', ?int $currentSupplierId = null): array
    {
        $cashAccounts = Account::whereIn('account_category', ['cash', 'cash_equivalent'])
            ->where('is_active', 1)->orderBy('code')->get();

        // Akun kredit Pemasukan Umum: pendapatan + hutang (kas masuk yang bukan
        // pendapatan — pinjaman bank, hutang ke pemilik, dsb → Dr Kas / Cr Hutang).
        // Akun hutang bersubledger dikecualikan supaya buku besar tetap cocok
        // dengan modulnya (AP faktur pembelian, uang muka & lebih bayar customer).
        $revenueAccounts = Account::whereIn('type', ['revenue', 'liability'])
            ->whereNotIn('code', AccountCodeEnum::SUBLEDGER_LIABILITY_CODES)
            ->where('is_active', 1)
            ->orderByRaw("FIELD(type, 'revenue', 'liability')")
            ->orderBy('code')->get();

        // Suppliers dengan saldo overpay (sum dari supplier_overpayments).
        // Untuk type=supplier_refund: hanya tampilkan yang saldo > 0, plus current supplier (edit mode).
        $suppliers = Supplier::where('is_active', 1)
            ->addSelect([
                'overpay_balance' => \App\Modules\Purchasing\Models\SupplierOverpayment::selectRaw('COALESCE(SUM(amount),0)')
                    ->whereColumn('supplier_id', 'suppliers.id'),
            ])
            ->orderBy('name')
            ->get();

        if ($type === 'supplier_refund') {
            $suppliers = $suppliers->filter(function ($s) use ($currentSupplierId) {
                return (float) ($s->overpay_balance ?? 0) > 0 || $s->id == $currentSupplierId;
            })->values();
        }

        $supplierOverpayAccount = Account::where('code', '1108')->first();

        return compact('type', 'cashAccounts', 'revenueAccounts', 'suppliers', 'supplierOverpayAccount');
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'date'            => 'required|date',
            'type'            => 'required|in:general,supplier_refund',
            'cash_account_id' => 'required|exists:accounts,id',
            'supplier_id'     => 'nullable|exists:suppliers,id',
            'payer'           => 'nullable|string|max:255',
            'reference'       => 'nullable|string|max:255',
            'notes'           => 'nullable|string',
            'lines'                            => 'required|array|min:1',
            'lines.*.account_id'               => 'required|exists:accounts,id',
            'lines.*.amount'                   => 'required|numeric|gt:0',
            'lines.*.description'              => 'nullable|string|max:255',
            'lines.*.supplier_overpayment_id'  => 'nullable|exists:supplier_overpayments,id',
        ]);
    }
}
