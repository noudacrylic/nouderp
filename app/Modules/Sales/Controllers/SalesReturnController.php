<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SalesInvoice;
use App\Models\Customer;
use App\DTO\SalesReturnDTO;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Sales\Services\CustomerCreditPaymentService;
use App\Modules\Sales\Models\SalesReturn;
use App\Core\Accounting\Account;
use App\Enums\AccountCodeEnum;
use Illuminate\Support\Facades\DB;

class SalesReturnController extends Controller
{
    public function index()
    {
        $returns = SalesReturn::with(['customer', 'invoice', 'salesOrder'])
            ->withCount(['items as repair_items_count' => fn($q) => $q->where('condition', 'repair')])
            ->latest('return_date')
            ->latest('id')
            ->get();

        return view('erp.sales.returns.index', compact('returns'));
    }

    public function create()
    {
        $customers = Customer::orderBy('name')->get(['id', 'name']);
        return view('erp.sales.returns.create', compact('customers'));
    }

    public function edit($id)
    {
        $return = SalesReturn::with(['items.product', 'customer', 'invoice.items.product', 'salesOrder.items.product'])->findOrFail($id);
        
        if ($return->status !== 'draft') {
            return redirect(list_url('sales.returns.index'))->with('error', 'Hanya retur draf yang dapat diedit.');
        }

        $customers = Customer::orderBy('name')->get(['id', 'name']);
        return view('erp.sales.returns.create', compact('customers', 'return'));
    }

    /**
     * API: Get confirmed/closed SOs belonging to a customer
     */
    public function getSalesOrders(Request $request)
    {
        $customerId = $request->get('customer_id');

        $orders = \App\Modules\Sales\Models\SalesOrder::query()
            ->with(['items.product', 'deliveries.items']) // Eager load SJ items for COGS calculation
            ->where('customer_id', $customerId)
            // 1. Harus punya SJ (Pengiriman) yang sudah posted
            ->whereHas('deliveries', function ($q) {
                $q->where('status', 'posted');
            })
            // 2. Harus sudah lunas (Uang Muka / Payment penuh)
            ->whereRaw('COALESCE(paid_amount, 0) >= grand_total')
            // 3. Menghindari double retur
            ->whereDoesntHave('returns')
            ->latest('order_date')
            ->get()
            ->map(function ($so) {
                return [
                    'id'             => $so->id,
                    'number'         => $so->order_number,
                    'date'           => $so->order_date ? \Carbon\Carbon::parse($so->order_date)->format('d/m/Y') : '-',
                    'grand_total'    => (float) $so->grand_total,
                    'status'         => strtoupper($so->status),
                    'delivery_count' => $so->deliveries->where('status', 'posted')->count(),
                    'items'          => $so->items->map(function($item) use ($so) {
                        // Ambil COGS dari SJ yang sudah terkirim
                        $cogsTotal = $so->deliveries->where('status', 'posted')->flatMap->items
                                       ->where('sales_order_item_id', $item->id)
                                       ->sum('cogs_total');

                        return [
                            'id'              => $item->id,
                            'product_id'      => $item->product_id,
                            'name'            => $item->product->name ?? $item->description,
                            'qty'             => (float) $item->qty,
                            'unit_price'      => (float) $item->unit_price,
                            'discount_amount' => (float) ($item->line_discount ?? 0),
                            'subtotal'        => (float) ($item->line_total ?? 0), // NET total
                            'cogs_total'      => (float) $cogsTotal,
                        ];
                    }),
                ];
            });

        return response()->json($orders);
    }

    /**
     * API: Get posted/partial invoices belonging to a customer
     */
    public function getInvoices(Request $request)
    {
        $customerId = $request->get('customer_id');

        $invoices = SalesInvoice::with(['items.product', 'delivery.items'])
            ->where('customer_id', $customerId)
            ->whereIn('status', ['posted', 'partial'])
            ->whereDoesntHave('returns', fn($q) => $q->where('status', 'posted'))
            ->latest('invoice_date')
            ->get()
            ->map(function ($inv) {
                $deliveryItems = $inv->delivery?->items ?? collect();

                return [
                    'id'             => $inv->id,
                    'number'         => $inv->invoice_number,
                    'date'           => $inv->invoice_date ? \Carbon\Carbon::parse($inv->invoice_date)->format('d/m/Y') : '-',
                    'grand_total'    => (float) $inv->grand_total,
                    'status'         => $inv->status_label,
                    'items'          => $inv->items->map(function ($item) use ($deliveryItems) {
                        $cogsTotal = (float) $item->cogs_total;

                        // For bundle items where cogs was stored as 0, recalculate from delivery components
                        if ($cogsTotal == 0 && $item->product && $item->product->sale_type === 'bundle') {
                            $components = \App\Core\Inventory\BundleComponent::where('bundle_product_id', $item->product_id)->get();
                            if ($components->isEmpty()) {
                                $components = \App\Core\Inventory\ProductBundle::where('bundle_product_id', $item->product_id)->get();
                            }
                            foreach ($components as $comp) {
                                $di = $deliveryItems->firstWhere('product_id', $comp->component_product_id);
                                if ($di) $cogsTotal += (float) $di->cogs_total;
                            }
                        }

                        return [
                            'id'              => $item->id,
                            'product_id'      => $item->product_id,
                            'name'            => $item->description,
                            'qty'             => (float) $item->qty,
                            'unit_price'      => (float) $item->unit_price,
                            'discount_amount' => (float) $item->discount_amount,
                            'subtotal'        => (float) $item->subtotal,
                            'cogs_total'      => $cogsTotal,
                        ];
                    }),
                ];
            });

        return response()->json($invoices);
    }

    /**
     * API: Get real-time customer credit balance (2106)
     */
    public function getCustomerBalance(Request $request)
    {
        $customerId = $request->get('customer_id');
        $service = app(CustomerCreditPaymentService::class);
        $balance = $service->getCustomerCreditBalance($customerId);

        return response()->json(['balance' => (float) $balance]);
    }

    /**
     * POST: Store or Update the return
     */
    public function store(Request $request, SalesReturnService $returnService)
    {
        $request->validate([
            'return_id'         => 'nullable|exists:sales_returns,id',
            'status'            => 'required|in:draft,posted',
            'invoice_id'        => 'required_without:sales_order_id|nullable|exists:sales_invoices,id',
            'sales_order_id'    => 'required_without:invoice_id|nullable|exists:sales_orders,id',
            'customer_id'       => 'required|exists:customers,id',
            'return_date'       => 'required|date',
            'items'             => 'required|array|min:1',
            'items.*.invoice_item_id' => 'required|integer', 
            'items.*.qty'       => 'required|numeric|min:0',
            'items.*.condition' => 'required|in:good,damaged,repair',
        ]);

        $items = collect($request->items)
            ->filter(fn($i) => (float)($i['qty'] ?? 0) > 0)
            ->values()
            ->toArray();

        if (empty($items)) {
            return back()->with('error', 'Tidak ada item dengan qty yang valid.')->withInput();
        }

        $dto = new SalesReturnDTO(
            customer_id:    (int) $request->customer_id,
            items:          $items,
            date:           $request->return_date,
            invoice_id:     $request->invoice_id ? (int)$request->invoice_id : null,
            sales_order_id: $request->sales_order_id ? (int)$request->sales_order_id : null,
        );

        try {
            if ($request->status === 'draft') {
                if ($request->return_id) {
                    $returnService->updateDraft((int)$request->return_id, $dto);
                    $msg = 'Draft retur berhasil diperbarui.';
                } else {
                    $returnService->saveDraft($dto);
                    $msg = 'Draft retur berhasil disimpan.';
                }
            } else {
                $returnService->post($dto, $request->return_id ? (int)$request->return_id : null);
                $msg = 'Retur berhasil diproses. Saldo customer telah diperbarui.';
            }

            return redirect(list_url('sales.returns.index'))->with('success', $msg);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }
    public function show($id)
    {
        $return = SalesReturn::with([
            'items.product',
            'customer',
            'invoice',
            'salesOrder',
        ])->findOrFail($id);

        $journal = \App\Core\Journal\Journal::where('reference_type', 'sales_return')
            ->where('reference_id', $return->id)
            ->with('lines.account')
            ->first();

        return view('erp.sales.returns.show', compact('return', 'journal'));
    }

    public function destroy($id)
    {
        $return = SalesReturn::findOrFail($id);
        if ($return->status !== 'draft') {
            return back()->with('error', 'Hanya retur draf yang dapat dihapus.');
        }
        $return->items()->delete();
        $return->delete();
        
        return back()->with('success', 'Draft retur berhasil dihapus.');
    }

    public function post($id, SalesReturnService $returnService)
    {
        $return = SalesReturn::with('items')->findOrFail($id);
        
        if ($return->status !== 'draft') {
            return back()->with('error', 'Hanya retur draf yang dapat diposting.');
        }

        $items = $return->items->map(function ($i) {
            return [
                'invoice_item_id' => $i->reference_item_id,
                'qty' => $i->qty,
                'condition' => $i->condition,
            ];
        })->toArray();

        $dto = new SalesReturnDTO(
            customer_id: $return->customer_id,
            items: $items,
            date: $return->return_date->format('Y-m-d'),
            invoice_id: $return->invoice_id,
            sales_order_id: $return->sales_order_id,
        );

        try {
            $returnService->post($dto, $return->id);
            return back()->with('success', 'Retur berhasil diposting. Saldo customer telah diperbarui.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $return = SalesReturn::with('items')->findOrFail($id);
        if ($return->status !== 'posted') {
            return back()->with('error', 'Hanya retur posted yang dapat di-void.');
        }

        // ── Dependency check: customer overpayment dari retur sudah dipakai sebagian/seluruhnya ──
        $overpay = \App\Models\CustomerOverpayment::where('reference', $return->return_number)->first();
        if ($overpay) {
            $originalAmount = (float) $return->grand_total;
            $remainingAmount = (float) $overpay->amount;
            // Bila amount di overpayment sudah berkurang dari nilai awal retur → sudah dipakai di payment
            if ($remainingAmount + 0.0001 < $originalAmount) {
                return back()->with('error', "Retur tidak bisa di-void: saldo customer overpayment dari retur ini sudah dipakai di Payment. Void payment terkait terlebih dahulu.");
            }
        }

        try {
            DB::transaction(function () use ($return) {
                // 1. Mark journal void
                \App\Core\Journal\Journal::where('reference_type', 'sales_return')
                    ->where('reference_id', $return->id)
                    ->update(['status' => 'void', 'voided_at' => now()]);

                // 2. Hapus customer overpayment yang dibuat saat retur post
                \App\Models\CustomerOverpayment::where('reference', $return->return_number)->delete();

                // 3. Reverse stok yang dimasukkan via FIFO saat retur post (kondisi good/repair)
                $engine = app(\App\Core\Inventory\InventoryEngine::class);
                foreach ($return->items as $item) {
                    if ((float) $item->qty <= 0) continue;
                    $product = \App\Core\Inventory\Product::find($item->product_id);
                    if (!$product || in_array($product->sale_type ?? null, ['service', 'non_stock'], true)) {
                        continue;
                    }

                    // Stok yang ditambahkan saat retur dapat dilacak via reference_type='sales_return'
                    $layers = \App\Core\Inventory\StockLayer::where('source_type', 'sales_return')
                        ->where('source_id', $return->id)
                        ->where('product_id', $item->product_id)
                        ->get();

                    foreach ($layers as $layer) {
                        $remaining = (float) $layer->qty_remaining;
                        if ($remaining < (float) $layer->qty_in - 0.0001) {
                            throw new \Exception("Stok dari retur {$return->return_number} sudah dipakai (terjual). Tidak bisa void.");
                        }
                    }

                    $totalQty = (float) $layers->sum('qty_in');
                    if ($totalQty <= 0) continue;

                    // Catat ledger qty_out untuk balikkan
                    $whId = $layers->first()->warehouse_id ?? null;
                    if ($whId) {
                        $engine->ledger(
                            $item->product_id,
                            $whId,
                            0,
                            $totalQty,
                            'sales_return_void',
                            $return->return_number,
                            'Void retur ' . $return->return_number,
                            $return->id
                        );
                    }

                    // Hapus FIFO layers
                    \App\Core\Inventory\StockLayer::where('source_type', 'sales_return')
                        ->where('source_id', $return->id)
                        ->where('product_id', $item->product_id)
                        ->delete();
                    \App\Models\InventoryCostLayer::where('reference_type', 'sales_return')
                        ->where('reference_id', $return->id)
                        ->where('product_id', $item->product_id)
                        ->delete();
                }

                $return->status = 'void';
                $return->save();
            });
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal void retur: ' . $e->getMessage());
        }

        return back()->with('success', 'Retur berhasil di-void.');
    }

    public function print($id)
    {
        return "Print page (to be implemented) for Return ID: " . $id;
    }
}

