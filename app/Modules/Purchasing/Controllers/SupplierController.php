<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Core\Accounting\Account;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $suppliers = Supplier::orderBy('name')
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                      ->orWhere('code', 'like', "%{$term}%")
                      ->orWhere('phone', 'like', "%{$term}%")
                      ->orWhere('city', 'like', "%{$term}%");
                });
            })
            ->get();

        // Aggregate per supplier untuk keperluan audit:
        //   ap_outstanding   = hutang ke supplier (purchase_invoices.outstanding_amount, status=posted)
        //   dp_balance       = saldo Uang Muka (1107) — sum(supplier_payments.remaining_amount, status=posted)
        //   overpay_balance  = saldo Piutang Lebih Bayar (1108) — sum(supplier_overpayments.amount)
        $apMap = \DB::table('purchase_invoices')
            ->select('supplier_id', \DB::raw('COALESCE(SUM(outstanding_amount), 0) as total'))
            ->where('status', 'posted')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $dpMap = \DB::table('supplier_payments')
            ->select('supplier_id', \DB::raw('COALESCE(SUM(remaining_amount), 0) as total'))
            ->where('status', 'posted')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $overpayMap = \DB::table('supplier_overpayments')
            ->select('supplier_id', \DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        // Pemasok yang sudah dipakai di transaksi → hanya bisa diarsipkan, tidak bisa dihapus.
        $usedIds = $this->usedSupplierIds($suppliers->pluck('id')->all());

        foreach ($suppliers as $s) {
            $s->ap_outstanding  = (float) ($apMap[$s->id] ?? 0);
            $s->dp_balance      = (float) ($dpMap[$s->id] ?? 0);
            $s->overpay_balance = (float) ($overpayMap[$s->id] ?? 0);
            $s->is_used         = in_array((int) $s->id, $usedIds, true);
        }

        return view('erp.purchasing.suppliers.index', compact('suppliers'));
    }

    /** ID pemasok yang sudah dipakai di transaksi mana pun (PO/faktur/retur/pembayaran/lebih-bayar). */
    private function usedSupplierIds(array $ids): array
    {
        if (empty($ids)) return [];

        $merged = array_merge(
            \DB::table('purchase_orders')->whereIn('supplier_id', $ids)->pluck('supplier_id')->all(),
            \DB::table('purchase_invoices')->whereIn('supplier_id', $ids)->pluck('supplier_id')->all(),
            \DB::table('purchase_returns')->whereIn('supplier_id', $ids)->pluck('supplier_id')->all(),
            \DB::table('supplier_payments')->whereIn('supplier_id', $ids)->pluck('supplier_id')->all(),
            \DB::table('supplier_overpayments')->whereIn('supplier_id', $ids)->pluck('supplier_id')->all(),
        );

        return array_values(array_unique(array_map('intval', $merged)));
    }

    public function create()
    {
        $accounts = Account::where('is_active', 1)->orderBy('code')->get();
        return view('erp.purchasing.suppliers.create', compact('accounts'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'npwp' => 'nullable|string',
            'tax_address' => 'nullable|string',
            'payment_term_days' => 'nullable|integer|min:0',
            'bank_name' => 'nullable|string',
            'bank_account_no' => 'nullable|string',
            'bank_account_name' => 'nullable|string',
            'account_payable_id' => 'nullable|exists:accounts,id',
            'account_dp_id' => 'nullable|exists:accounts,id',
        ]);

        $data['code'] = 'SUP-' . str_pad((Supplier::max('id') + 1), 4, '0', STR_PAD_LEFT);
        $data['payment_term_days'] = $data['payment_term_days'] ?? 30;
        $data['is_active'] = 1;

        Supplier::create($data);

        return redirect(list_url('purchasing.suppliers.index'))->with('success', 'Supplier dibuat.');
    }

    public function edit($id)
    {
        $supplier = Supplier::findOrFail($id);
        $accounts = Account::where('is_active', 1)->orderBy('code')->get();
        return view('erp.purchasing.suppliers.edit', compact('supplier', 'accounts'));
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'npwp' => 'nullable|string',
            'tax_address' => 'nullable|string',
            'payment_term_days' => 'nullable|integer|min:0',
            'bank_name' => 'nullable|string',
            'bank_account_no' => 'nullable|string',
            'bank_account_name' => 'nullable|string',
            'account_payable_id' => 'nullable|exists:accounts,id',
            'account_dp_id' => 'nullable|exists:accounts,id',
            'is_active' => 'nullable|boolean',
        ]);
        $supplier->update($data);
        return redirect(list_url('purchasing.suppliers.index'))->with('success', 'Supplier diupdate.');
    }

    public function show($id)
    {
        $supplier = Supplier::with(['purchaseOrders', 'purchaseInvoices'])->findOrFail($id);
        return view('erp.purchasing.suppliers.show', compact('supplier'));
    }

    /** Arsipkan pemasok (nonaktif) — tetap aman walau sudah dipakai transaksi. */
    public function archive($id)
    {
        Supplier::findOrFail($id)->update(['is_active' => 0]);
        return back()->with('success', 'Pemasok diarsipkan (nonaktif).');
    }

    /** Aktifkan kembali pemasok yang diarsipkan. */
    public function restore($id)
    {
        Supplier::findOrFail($id)->update(['is_active' => 1]);
        return back()->with('success', 'Pemasok diaktifkan kembali.');
    }

    /** Hapus permanen — HANYA bila pemasok belum dipakai di transaksi mana pun. */
    public function destroy($id)
    {
        $supplier = Supplier::findOrFail($id);

        if (! empty($this->usedSupplierIds([(int) $id]))) {
            return back()->with('error', 'Pemasok tidak bisa dihapus karena sudah dipakai di transaksi. Gunakan Arsipkan.');
        }

        $supplier->delete();
        return back()->with('success', 'Pemasok dihapus permanen.');
    }

    public function search(Request $request)
    {
        $q = $request->q;
        $rows = Supplier::where('is_active', 1)
            ->where(function ($w) use ($q) {
                $w->where('name', 'like', "%$q%")->orWhere('code', 'like', "%$q%");
            })
            ->limit(20)
            ->get(['id', 'code', 'name', 'payment_term_days']);

        return response()->json($rows);
    }

    public function storeAjax(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'city' => 'nullable|string',
            'address' => 'nullable|string',
            'payment_term_days' => 'nullable|integer|min:0',
        ]);
        $data['code'] = 'SUP-' . str_pad((Supplier::max('id') + 1), 4, '0', STR_PAD_LEFT);
        $data['payment_term_days'] = $data['payment_term_days'] ?? 30;
        $data['is_active'] = 1;
        $supplier = Supplier::create($data);
        return response()->json([
            'id' => $supplier->id,
            'code' => $supplier->code,
            'name' => $supplier->name,
            'payment_term_days' => $supplier->payment_term_days,
        ]);
    }
}
