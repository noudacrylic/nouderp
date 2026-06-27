<?php

namespace App\Modules\Sales\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\WarrantyOrder;
use App\Models\Customer;
use App\Core\Inventory\Warehouse;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\WarrantyOrderService;
use App\Core\Journal\Journal;

class WarrantyOrderController extends Controller
{
    public function index()
    {
        $warranties = WarrantyOrder::with(['customer', 'invoice', 'salesOrder'])
            ->latest('warranty_date')
            ->paginate(per_page_size())
            ->withQueryString();

        return view('erp.sales.warranty.index', compact('warranties'));
    }

    public function create()
    {
        $customers  = Customer::where('is_active', true)->orderBy('name')->get();
        $warehouses = Warehouse::orderBy('name')->get();

        return view('erp.sales.warranty.create', compact('customers', 'warehouses'));
    }

    public function store(Request $request, WarrantyOrderService $service)
    {
        $request->validate([
            'form_status'  => 'required|in:draft,post',
            'customer_id'  => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'warranty_date'=> 'required|date',
            'type'         => 'required|in:repair',
            'items'        => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty'        => 'required|numeric|min:0',
        ]);

        // Form mengirim semua baris (item dari faktur yang tak diperbaiki ber-qty 0) — buang
        // baris qty 0 sebelum disimpan, supaya validasi tidak menolaknya & tak ada item hantu.
        $items = collect($request->input('items', []))
            ->filter(fn ($i) => (float) ($i['qty'] ?? 0) > 0)
            ->values()
            ->all();
        if (empty($items)) {
            return back()->with('error', 'Tidak ada item dengan qty yang valid.')->withInput();
        }

        try {
            $warranty = $service->create(array_merge($request->only([
                'customer_id', 'invoice_id', 'sales_order_id', 'warehouse_id',
                'warranty_date', 'type', 'issue_description', 'notes',
            ]), ['items' => $items]));

            if ($request->form_status === 'post') {
                $service->receive($warranty->id);
                $msg = 'Garansi ' . $warranty->warranty_number . ' berhasil dibuat, barang diterima & diposting.';
            } else {
                $msg = 'Draft garansi ' . $warranty->warranty_number . ' berhasil disimpan.';
            }

            return redirect(list_url('sales.warranty.index'))->with('success', $msg);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function show(int $id, WarrantyOrderService $service)
    {
        $warranty = WarrantyOrder::with([
            'customer', 'invoice', 'salesOrder', 'warehouse',
            'items.product', 'delivery.items.product',
        ])->findOrFail($id);

        // OP Perbaikan yang terkait garansi ini (link satu arah dari production_orders)
        $repairOrders = \App\Modules\Production\Models\ProductionOrder::where('repair_source_type', 'warranty')
            ->where('repair_source_id', $warranty->id)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('id')
            ->get(['id', 'order_number', 'status', 'type']);

        // Auto-sync: kalau ada OP perbaikan yang sudah difinalisasi tapi garansi belum
        // "Selesai Diperbaiki", naikkan statusnya otomatis (mis. OP difinalisasi sebelum fitur ini ada).
        if ($warranty->type === 'repair'
            && $warranty->status === 'posted'
            && $repairOrders->contains(fn ($o) => $o->status === 'finalized')) {
            $service->markRepairedFromProduction($warranty->id);
            $warranty->refresh();
        }

        $postJournal = Journal::with('lines.account')
            ->where('reference_type', 'warranty_post')
            ->where('reference_id', $warranty->id)
            ->first();

        $journal = null;
        if ($warranty->delivery && $warranty->delivery->status === 'posted') {
            $journal = Journal::with('lines.account')
                ->where('reference_type', 'warranty_delivery')
                ->where('reference_id', $warranty->delivery->id)
                ->first();
        }

        $cashAccounts = \App\Core\Accounting\Account::where(function ($q) {
            $q->where('is_cash_account', true)
              ->orWhereIn('account_category', ['cash', 'cash_equivalent']);
        })->where('is_active', true)->orderBy('code')->get();

        return view('erp.sales.warranty.show', compact('warranty', 'postJournal', 'journal', 'repairOrders', 'cashAccounts'));
    }

    public function edit(int $id)
    {
        $warranty = WarrantyOrder::with('items.product')->findOrFail($id);

        if ($warranty->status !== 'draft') {
            return redirect()->route('sales.warranty.show', $id)
                ->with('error', 'Hanya garansi draft yang dapat diubah.');
        }

        $customers  = Customer::where('is_active', true)->orderBy('name')->get();
        $warehouses = Warehouse::orderBy('name')->get();

        return view('erp.sales.warranty.create', compact('warranty', 'customers', 'warehouses'));
    }

    public function update(Request $request, int $id, WarrantyOrderService $service)
    {
        $request->validate([
            'customer_id'  => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'warranty_date'=> 'required|date',
            'type'         => 'required|in:repair',
            'items'        => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty'        => 'required|numeric|min:0',
        ]);

        // Buang baris qty 0 (item faktur yang tak diperbaiki) sebelum simpan.
        $items = collect($request->input('items', []))
            ->filter(fn ($i) => (float) ($i['qty'] ?? 0) > 0)
            ->values()
            ->all();
        if (empty($items)) {
            return back()->with('error', 'Tidak ada item dengan qty yang valid.')->withInput();
        }

        try {
            $service->update($id, array_merge($request->only([
                'customer_id', 'invoice_id', 'sales_order_id', 'warehouse_id',
                'warranty_date', 'type', 'issue_description', 'notes',
            ]), ['items' => $items]));

            return redirect(list_url('sales.warranty.index'))
                ->with('success', 'Garansi berhasil diperbarui.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(int $id, WarrantyOrderService $service)
    {
        try {
            $service->delete($id);
            return back()->with('success', 'Draft garansi berhasil dihapus.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function receive(int $id, WarrantyOrderService $service)
    {
        try {
            $service->receive($id);
            return back()->with('success', 'Barang diterima & garansi diposting. Jurnal Dr 1131 / Cr 1132 telah dicatat.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function markRepaired(Request $request, int $id, WarrantyOrderService $service)
    {
        $request->validate([
            'repair_cost' => 'nullable|numeric|min:0',
        ]);

        try {
            $service->markRepaired($id, $request->filled('repair_cost') ? (float) $request->repair_cost : null);
            return back()->with('success', 'Perbaikan ditandai selesai. SJ pengiriman balik dapat dibuat.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function createDelivery(Request $request, int $id, WarrantyOrderService $service)
    {
        $request->validate([
            'delivery_date'       => 'nullable|date',
            'service_fee'         => 'nullable|numeric|min:0',
            'shipping_cost'       => 'nullable|numeric|min:0',
            'shipping_paid_by'    => 'nullable|in:customer,company',
            'fee_cash_account_id' => 'nullable|exists:accounts,id',
        ]);

        // Validasi kondisional: jika ada biaya, akun kas wajib; jika ada ongkir, penanggung wajib
        $hasFee = (float) $request->input('service_fee', 0) > 0
               || (float) $request->input('shipping_cost', 0) > 0;
        if ($hasFee && !$request->filled('fee_cash_account_id')) {
            return back()->withInput()->with('error', 'Pilih akun Kas/Bank untuk biaya garansi.');
        }
        if ((float) $request->input('shipping_cost', 0) > 0 && !$request->filled('shipping_paid_by')) {
            return back()->withInput()->with('error', 'Pilih ongkir dibayar customer atau perusahaan.');
        }

        try {
            $service->createDelivery($id, [
                'service_fee'         => (float) $request->input('service_fee', 0),
                'shipping_cost'       => (float) $request->input('shipping_cost', 0),
                'shipping_paid_by'    => $request->input('shipping_paid_by'),
                'fee_cash_account_id' => $request->input('fee_cash_account_id'),
            ], $request->input('delivery_date'));
            return back()->with('success', 'SJ Garansi berhasil dibuat. Klik "Post SJ" untuk memproses.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function postDelivery(Request $request, int $id, int $deliveryId, WarrantyOrderService $service)
    {
        try {
            $service->postDelivery($deliveryId);
            return back()->with('success', 'SJ Garansi berhasil diposting. Stok telah dikurangi & jurnal dicatat.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void(int $id)
    {
        $warranty = WarrantyOrder::with('delivery')->findOrFail($id);

        if (in_array($warranty->status, ['void', 'draft', 'cancelled'], true)) {
            return back()->with('error', 'Garansi ini tidak bisa di-void.');
        }

        // ── Dependency check: SJ garansi (outbound) sudah posted ──
        if ($warranty->delivery && $warranty->delivery->status === 'posted') {
            return back()->with('error', "Garansi tidak bisa di-void: Surat Jalan {$warranty->delivery->delivery_number} sudah dipost. Void surat jalan tersebut terlebih dahulu.");
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($warranty) {
                // Mark journal warranty_post as void
                Journal::where('reference_type', 'warranty_post')
                    ->where('reference_id', $warranty->id)
                    ->update(['status' => 'void', 'voided_at' => now()]);

                // Reverse stok masuk yang dicatat saat receive (1131/1132 dsb)
                // Dibalik via ledger qty_out untuk setiap item warranty.
                $engine = app(\App\Core\Inventory\InventoryEngine::class);
                foreach ($warranty->items as $item) {
                    if ((float) $item->qty <= 0) continue;
                    if (!$item->product_id || !$warranty->warehouse_id) continue;

                    $product = \App\Core\Inventory\Product::find($item->product_id);
                    if (!$product || in_array($product->sale_type ?? null, ['service', 'non_stock'], true)) {
                        continue;
                    }

                    try {
                        $engine->ledger(
                            $item->product_id,
                            $warranty->warehouse_id,
                            0,
                            (float) $item->qty,
                            'warranty_void',
                            $warranty->warranty_number,
                            'Void warranty ' . $warranty->warranty_number,
                            $warranty->id
                        );
                    } catch (\Throwable $e) {
                        // Swallow stock-not-enough — warranty receive may already be reversed
                    }
                }

                // Hapus draft delivery jika ada
                if ($warranty->delivery && $warranty->delivery->status !== 'posted') {
                    $warranty->delivery->items()->delete();
                    $warranty->delivery->delete();
                }

                $warranty->status = 'void';
                $warranty->save();
            });
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal void garansi: ' . $e->getMessage());
        }

        return back()->with('success', 'Garansi berhasil di-void.');
    }

    /**
     * API: Get posted invoices for a customer (with items for auto-fill)
     */
    public function getInvoices(Request $request)
    {
        $customerId = $request->get('customer_id');

        $invoices = SalesInvoice::with(['items.product', 'delivery.items'])
            ->where('customer_id', $customerId)
            ->whereIn('status', ['posted', 'partial'])
            ->latest('invoice_date')
            ->get()
            ->map(function ($inv) {
                $deliveryItems = $inv->delivery?->items ?? collect();

                return [
                    'id'          => $inv->id,
                    'number'      => $inv->invoice_number,
                    'date'        => $inv->invoice_date
                        ? \Carbon\Carbon::parse($inv->invoice_date)->format('d/m/Y')
                        : '-',
                    'grand_total' => (float) $inv->grand_total,
                    'status'      => strtoupper($inv->status instanceof \BackedEnum ? $inv->status->value : $inv->status),
                    'items'       => $inv->items->map(function ($item) use ($deliveryItems) {
                        $cogsTotal = (float) $item->cogs_total;

                        return [
                            'id'         => $item->id,
                            'product_id' => $item->product_id,
                            'sku'        => $item->product?->sku ?? '',
                            'name'       => $item->description ?? $item->product?->name ?? '',
                            'qty'        => (float) $item->qty,
                            'cogs_total' => $cogsTotal,
                        ];
                    }),
                ];
            });

        return response()->json($invoices);
    }

    /**
     * API: Get confirmed SOs with posted deliveries for a customer
     */
    public function getSalesOrders(Request $request)
    {
        $customerId = $request->get('customer_id');

        $orders = SalesOrder::query()
            ->with(['items.product', 'deliveries.items'])
            ->where('customer_id', $customerId)
            ->whereIn('status', ['confirmed', 'closed', 'partial'])
            ->whereHas('deliveries', fn($q) => $q->where('status', 'posted'))
            ->latest('order_date')
            ->get()
            ->map(function ($so) {
                return [
                    'id'          => $so->id,
                    'number'      => $so->order_number,
                    'date'        => $so->order_date
                        ? \Carbon\Carbon::parse($so->order_date)->format('d/m/Y')
                        : '-',
                    'grand_total' => (float) $so->grand_total,
                    'status'      => strtoupper($so->status),
                    'items'       => $so->items->map(function ($item) use ($so) {
                        $cogsTotal = $so->deliveries
                            ->where('status', 'posted')
                            ->flatMap->items
                            ->where('sales_order_item_id', $item->id)
                            ->sum('cogs_total');

                        return [
                            'id'         => $item->id,
                            'product_id' => $item->product_id,
                            'sku'        => $item->product?->sku ?? '',
                            'name'       => $item->product?->name ?? $item->description ?? '',
                            'qty'        => (float) $item->qty,
                            'cogs_total' => (float) $cogsTotal,
                        ];
                    }),
                ];
            });

        return response()->json($orders);
    }
}
