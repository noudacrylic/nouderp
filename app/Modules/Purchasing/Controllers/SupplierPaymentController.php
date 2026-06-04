<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Services\SupplierPaymentService;
use App\Models\Supplier;
use App\Core\Accounting\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\InlinesPrintAssets;

class SupplierPaymentController extends Controller
{
    use InlinesPrintAssets;

    public function __construct(
        protected SupplierPaymentService $service
    ) {}

    public function print($id)
    {
        $payment = SupplierPayment::with(['supplier', 'bankAccount', 'allocations.invoice'])->findOrFail($id);
        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');
        return view('erp.purchasing.payments.print', compact('payment', 'profile'));
    }

    public function downloadPdf($id)
    {
        $payment = SupplierPayment::with(['supplier', 'bankAccount', 'allocations.invoice'])->findOrFail($id);
        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        $html = view('erp.purchasing.payments.print', [
            'payment' => $payment,
            'profile' => $profile,
            'pdfMode' => true,
        ])->render();
        $html = $this->inlineLocalAssets($html);

        $bs = \Spatie\Browsershot\Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(0, 0, 0, 0)
            ->emulateMedia('print')
            ->timeout(120)
            ->waitUntilNetworkIdle();

        $nodePath = env('BROWSERSHOT_NODE_PATH', PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\node.exe' : null);
        $npmPath  = env('BROWSERSHOT_NPM_PATH',  PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\npm.cmd'  : null);
        if ($nodePath) $bs->setNodeBinary($nodePath);
        if ($npmPath)  $bs->setNpmBinary($npmPath);

        $pdf = $bs->pdf();
        $filename = 'PembayaranSupplier_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $payment->payment_number) . '.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function index(Request $request)
    {
        $payments = SupplierPayment::with(['supplier', 'bankAccount', 'allocations.invoice'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('payment_date', '>=', $d))
            ->when($request->date_to,   fn($q, $d) => $q->whereDate('payment_date', '<=', $d))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('payment_number', 'like', "%{$term}%")
                      ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $suppliers = Supplier::where('is_active', 1)->orderBy('name')->get();
        return view('erp.purchasing.payments.index', compact('payments', 'suppliers'));
    }

    public function create()
    {
        $suppliers = Supplier::where('is_active', 1)->orderBy('name')->get();
        $cashAccounts = Account::whereIn('account_category', ['cash', 'cash_equivalent'])
            ->where('is_active', 1)
            ->orderBy('code')
            ->get();
        return view('erp.purchasing.payments.create', compact('suppliers', 'cashAccounts'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|in:cash,bank,transfer',
            'bank_account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0',
            'overpay' => 'nullable|numeric|min:0',
            'used_balance' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'allocations' => 'nullable|array',
            'allocations.*.purchase_invoice_id' => 'required_with:allocations|exists:purchase_invoices,id',
            'allocations.*.amount_applied' => 'required_with:allocations|numeric|gt:0',
        ]);
        $action = $request->input('_after_save', '');
        try {
            $payment = $this->service->createDraft($data);
            if ($action === 'post' || $action === 'print') {
                $this->service->post($payment);
            }
            if ($action === 'print') {
                return redirect()->route('purchasing.payments.show', ['id' => $payment->id, 'print' => 1])
                    ->with('success', 'Payment dibuat & POSTED.');
            }
            $msg = $action === 'post' ? 'Payment dibuat & POSTED.' : 'Payment dibuat sebagai draft.';
            return redirect(list_url('purchasing.payments.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $payment = SupplierPayment::with(['supplier', 'bankAccount', 'allocations.invoice'])->findOrFail($id);
        return view('erp.purchasing.payments.show', compact('payment'));
    }

    public function edit($id)
    {
        $payment = SupplierPayment::with('allocations')->findOrFail($id);
        if ($payment->status !== 'draft') {
            return redirect()->route('purchasing.payments.show', $payment->id)
                ->with('error', 'Hanya payment draft yang bisa diedit.');
        }
        $suppliers = Supplier::where('is_active', 1)->orderBy('name')->get();
        $cashAccounts = Account::whereIn('account_category', ['cash', 'cash_equivalent'])
            ->where('is_active', 1)
            ->orderBy('code')
            ->get();
        return view('erp.purchasing.payments.edit', compact('payment', 'suppliers', 'cashAccounts'));
    }

    public function update(Request $request, $id)
    {
        $payment = SupplierPayment::findOrFail($id);
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|in:cash,bank,transfer',
            'bank_account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0',
            'overpay' => 'nullable|numeric|min:0',
            'used_balance' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'allocations' => 'nullable|array',
            'allocations.*.purchase_invoice_id' => 'required_with:allocations|exists:purchase_invoices,id',
            'allocations.*.amount_applied' => 'required_with:allocations|numeric|gt:0',
        ]);
        $action = $request->input('_after_save', '');
        try {
            $this->service->update($payment, $data);
            if ($action === 'post' || $action === 'print') {
                $this->service->post($payment->refresh());
            }
            if ($action === 'print') {
                return redirect()->route('purchasing.payments.show', ['id' => $payment->id, 'print' => 1])
                    ->with('success', 'Payment diupdate & POSTED.');
            }
            $msg = $action === 'post' ? 'Payment diupdate & POSTED.' : 'Payment diupdate (draft).';
            return redirect(list_url('purchasing.payments.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function post($id)
    {
        $payment = SupplierPayment::findOrFail($id);
        try {
            $this->service->post($payment);
            return back()->with('success', 'Payment POSTED.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $payment = SupplierPayment::findOrFail($id);

        if (!$payment->canBeVoided()) {
            return back()->with('error', 'Hanya payment posted yang dapat di-void.');
        }

        try {
            $this->service->void($payment);
            return back()->with('success', 'Payment di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $payment = SupplierPayment::findOrFail($id);
        if ($payment->status !== 'draft') {
            return back()->with('error', 'Hanya payment draft yang bisa dihapus.');
        }
        $payment->allocations()->delete();
        $payment->delete();
        return back()->with('success', 'Payment dihapus.');
    }

    public function openInvoices($supplierId)
    {
        $invoices = $this->service->getOpenInvoices((int) $supplierId);
        return response()->json($invoices->map(fn($i) => [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'invoice_date' => $i->invoice_date->format('Y-m-d'),
            'total' => $i->total,
            'paid_amount' => $i->paid_amount,
            'outstanding_amount' => $i->outstanding_amount,
        ]));
    }

    public function openDp($supplierId)
    {
        return response()->json([
            'dp_balance' => $this->service->getSupplierDpBalance((int) $supplierId),
            'overpay_balance' => $this->service->getSupplierOverpayBalance((int) $supplierId),
        ]);
    }

    public function openPos($supplierId)
    {
        $pos = \App\Modules\Purchasing\Models\PurchaseOrder::with('items')
            ->where('supplier_id', (int) $supplierId)
            ->where('status', 'posted')
            // Exclude PO yang sudah pernah retur DP (purchase_returns posted, return_type='po')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('purchase_returns')
                    ->whereColumn('purchase_returns.purchase_order_id', 'purchase_orders.id')
                    ->where('return_type', 'po')
                    ->where('status', 'posted');
            })
            ->orderByDesc('po_date')
            ->orderByDesc('id')
            ->get();

        $rows = $pos->filter(function ($po) {
            $totalQty = (float) $po->items->sum('qty');
            $invoicedQty = (float) $po->items->sum('qty_invoiced');
            return $totalQty <= 0 || $invoicedQty < $totalQty;
        })->map(function ($po) {
            $totalQty = (float) $po->items->sum('qty');
            $invoicedQty = (float) $po->items->sum('qty_invoiced');
            $invoicedRatio = $totalQty > 0 ? min(1.0, $invoicedQty / $totalQty) : 0;
            $remainingValue = (float) $po->total * (1 - $invoicedRatio);
            return [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'po_date' => $po->po_date->format('Y-m-d'),
                'total' => (float) $po->total,
                'invoiced_ratio' => $invoicedRatio,
                'remaining_value' => round($remainingValue, 2),
            ];
        })->values();

        return response()->json($rows);
    }
}
