<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseReturnService;
use App\Models\Supplier;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    public function __construct(
        protected PurchaseReturnService $service
    ) {}

    public function index(Request $request)
    {
        $returns = PurchaseReturn::with(['supplier', 'purchaseInvoice', 'purchaseOrder'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->return_type, fn($q, $t) => $q->where('return_type', $t))
            ->when($request->supplier_id, fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('return_date', '>=', $d))
            ->when($request->date_to,   fn($q, $d) => $q->whereDate('return_date', '<=', $d))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('return_number', 'like', "%{$term}%")
                      ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))
                      ->orWhereHas('purchaseInvoice', fn($i) => $i->where('invoice_number', 'like', "%{$term}%"))
                      ->orWhereHas('purchaseOrder', fn($p) => $p->where('po_number', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('return_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $suppliers = Supplier::where('is_active', 1)->orderBy('name')->get();
        return view('erp.purchasing.returns.index', compact('returns', 'suppliers'));
    }

    public function create()
    {
        return view('erp.purchasing.returns.create');
    }

    public function edit($id)
    {
        $return = PurchaseReturn::with([
            'items.product',
            'supplier',
            'purchaseInvoice.items.product',
            'purchaseOrder',
        ])->findOrFail($id);

        if ($return->status !== 'draft') {
            return redirect(list_url('purchasing.returns.index'))
                ->with('error', 'Hanya retur draft yang bisa diedit.');
        }
        return view('erp.purchasing.returns.create', compact('return'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'return_id'           => 'nullable|exists:purchase_returns,id',
            'status'              => 'required|in:draft,posted',
            'return_type'         => 'required|in:invoice,po',
            'supplier_id'         => 'required|exists:suppliers,id',
            'return_date'         => 'required|date',
            'purchase_invoice_id' => 'required_if:return_type,invoice|nullable|exists:purchase_invoices,id',
            'purchase_order_id'   => 'required_if:return_type,po|nullable|exists:purchase_orders,id',
            'refund_amount'       => 'required_if:return_type,po|nullable|numeric|gt:0',
            'items'               => 'required_if:return_type,invoice|nullable|array',
            // Allow 0; we filter di bawah. Item dengan qty>0 ≥ 1 dijamin di service.
            'items.*.purchase_invoice_item_id' => 'required_with:items|exists:purchase_invoice_items,id',
            'items.*.qty'         => 'required_with:items|numeric|min:0',
            'notes'               => 'nullable|string',
        ]);

        $data = $request->only([
            'return_type', 'supplier_id', 'return_date',
            'purchase_invoice_id', 'purchase_order_id', 'refund_amount',
            'items', 'notes',
        ]);

        // Filter item qty = 0 (form submit semua row, termasuk yang tidak diisi).
        if ($request->return_type === 'invoice') {
            $data['items'] = collect($request->input('items', []))
                ->filter(fn($i) => (float) ($i['qty'] ?? 0) > 0)
                ->values()
                ->all();
            if (empty($data['items'])) {
                return back()->withInput()->with('error', 'Minimal harus ada 1 item dengan qty retur > 0.');
            }
        }

        try {
            if ($request->return_id) {
                $return = PurchaseReturn::findOrFail($request->return_id);
                $this->service->update($return, $data);
            } else {
                $return = $this->service->createDraft($data);
            }

            if ($request->status === 'posted') {
                $this->service->post($return->refresh());
                return redirect()->route('purchasing.returns.show', $return->id)
                    ->with('success', 'Retur posted.');
            }
            return redirect(list_url('purchasing.returns.index'))
                ->with('success', $request->return_id ? 'Draft retur diupdate.' : 'Draft retur disimpan.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $return = PurchaseReturn::with([
            'items.product',
            'supplier',
            'purchaseInvoice',
            'purchaseOrder',
            'warehouse',
        ])->findOrFail($id);

        $journal = \App\Core\Journal\Journal::where('reference_type', 'purchase_return')
            ->where('reference_id', $return->id)
            ->with('lines.account')
            ->first();

        return view('erp.purchasing.returns.show', compact('return', 'journal'));
    }

    public function post($id)
    {
        $return = PurchaseReturn::findOrFail($id);
        try {
            $this->service->post($return);
            return back()->with('success', 'Retur POSTED.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $return = PurchaseReturn::findOrFail($id);

        if (!$return->canBeVoided()) {
            return back()->with('error', 'Hanya retur posted yang dapat di-void.');
        }

        try {
            $this->service->void($return);
            return back()->with('success', 'Retur di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $return = PurchaseReturn::findOrFail($id);
        try {
            $this->service->destroy($return);
            return back()->with('success', 'Draft retur dihapus.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────
    //  AJAX
    // ──────────────────────────────────────────────────────────

    public function getInvoices(Request $request)
    {
        $supplierId = (int) $request->get('supplier_id');
        if (!$supplierId) return response()->json([]);

        $invoices = PurchaseInvoice::with('items.product')
            ->where('supplier_id', $supplierId)
            ->where('status', 'posted')
            ->orderByDesc('invoice_date')
            ->get()
            ->map(function ($inv) {
                $items = $inv->items->map(function ($i) {
                    $remaining = (float) $i->qty - (float) $i->qty_returned;
                    return [
                        'id'                 => $i->id,
                        'product_id'         => $i->product_id,
                        'name'               => $i->product->name ?? $i->description,
                        'qty'                => (float) $i->qty,
                        'qty_returned'       => (float) $i->qty_returned,
                        'qty_remaining'      => max(0, $remaining),
                        'price'              => (float) $i->price,
                        'discount'           => (float) $i->discount,
                        'subtotal'           => (float) $i->subtotal,
                        'final_unit_cost'    => (float) $i->final_unit_cost,
                        'refund_per_unit'    => round((float) $i->final_unit_cost, 4),
                    ];
                })->filter(fn($it) => $it['qty_remaining'] > 0)->values();

                return [
                    'id'                 => $inv->id,
                    'number'             => $inv->invoice_number,
                    'date'               => $inv->invoice_date ? \Carbon\Carbon::parse($inv->invoice_date)->format('d/m/Y') : '-',
                    'grand_total'        => (float) $inv->total,
                    'outstanding_amount' => (float) $inv->outstanding_amount,
                    'status'             => strtoupper($inv->status),
                    'items'              => $items,
                ];
            })
            ->filter(fn($i) => count($i['items']) > 0)
            ->values();

        return response()->json($invoices);
    }

    public function getOrders(Request $request)
    {
        $supplierId = (int) $request->get('supplier_id');
        if (!$supplierId) return response()->json([]);

        $orders = PurchaseOrder::with('items.product')
            ->where('supplier_id', $supplierId)
            ->where('status', 'posted')
            ->whereDoesntHave('invoices', fn($q) => $q->where('status', '<>', 'void'))
            ->orderByDesc('po_date')
            ->get()
            ->map(function ($po) {
                return [
                    'id'           => $po->id,
                    'number'       => $po->po_number,
                    'date'         => $po->po_date ? \Carbon\Carbon::parse($po->po_date)->format('d/m/Y') : '-',
                    'grand_total'  => (float) $po->total,
                    'status'       => strtoupper($po->status),
                    'items_count'  => $po->items->count(),
                ];
            });
        return response()->json($orders);
    }

    public function getDpBalance(Request $request)
    {
        $supplierId = (int) $request->get('supplier_id');
        if (!$supplierId) return response()->json(['balance' => 0]);
        return response()->json([
            'balance' => $this->service->getSupplierDpBalance($supplierId),
        ]);
    }
}
