<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\SalesQuotation;
use App\Modules\Sales\Models\SalesAdvance;
use App\Services\NumberGeneratorService;

use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Concerns\InlinesPrintAssets;

class SalesOrderController extends Controller
{
    use InlinesPrintAssets;

    public function index(Request $request)
    {
        $query = SalesOrder::with([
                'customer',
                'deliveries' => fn($q) => $q->whereIn('status', ['draft', 'posted'])->orderBy('id'),
            ])
            ->withExists('returns as has_return');

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%$search%")
                    ->orWhere('pickup_code', 'like', "%$search%")
                    ->orWhereHas('customer', function ($c) use ($search) {
                        $c->where('name', 'like', "%$search%");
                    });
            });
        }

        if ($request->from) {
            $query->whereDate('order_date', '>=', $request->from);
        }

        if ($request->to) {
            $query->whereDate('order_date', '<=', $request->to);
        }

        if ($request->marketplace === 'marketplace') {
            $query->whereHas('customer', fn ($c) => $c->where('is_marketplace', true));
        } elseif ($request->marketplace === 'non_marketplace') {
            $query->whereHas('customer', fn ($c) => $c->where('is_marketplace', false));
        }

        // FILTER UMUR DOKUMEN BELUM DIPROSES — selaras dengan age-badge (belum invoice & bukan void).
        // Bucket: < 1 minggu | 1 minggu–1 bulan | 1 bulan–3 bulan | > 3 bulan (umur dari order_date).
        if ($request->age) {
            $today = \Carbon\Carbon::today();
            $w1 = $today->copy()->subWeek();      // batas 1 minggu
            $m1 = $today->copy()->subMonth();     // batas 1 bulan
            $m3 = $today->copy()->subMonths(3);   // batas 3 bulan

            // Hanya yang "belum diproses": bukan void/cancelled & belum punya invoice (posted/draft).
            $query->whereNotIn('status', ['void', 'cancelled'])
                ->whereDoesntHave('invoices', fn ($q) => $q->whereIn('status', ['posted', 'draft']));

            if ($request->age === 'lt_1w') {
                $query->whereDate('order_date', '>=', $w1);
            } elseif ($request->age === '1w_1m') {
                $query->whereDate('order_date', '<', $w1)->whereDate('order_date', '>=', $m1);
            } elseif ($request->age === '1m_3m') {
                $query->whereDate('order_date', '<', $m1)->whereDate('order_date', '>=', $m3);
            } elseif ($request->age === 'gt_3m') {
                $query->whereDate('order_date', '<', $m3);
            }
        }

        // FILTER STATUS GABUNGAN — 6 nilai: draft | not_invoiced | partial | invoiced_full | retur | void
        if ($request->status) {
            $status = $request->status;

            if ($status === 'retur') {
                $query->whereHas('returns');
            } elseif ($status === 'void') {
                $query->whereIn('status', ['void', 'cancelled'])->whereDoesntHave('returns');
            } elseif ($status === 'draft') {
                $query->where('status', 'draft')->whereDoesntHave('returns');
            } else {
                // not_invoiced / partial / invoiced_full — hanya berlaku untuk SO confirmed tanpa retur
                $query->where('status', 'confirmed')->whereDoesntHave('returns');

                $query->addSelect(['total_so_qty' => \App\Modules\Sales\Models\SalesOrderItem::selectRaw('SUM(qty)')
                    ->whereColumn('sales_order_id', 'sales_orders.id')
                ]);

                // Draft invoice ikut dihitung sebagai "punya invoice"
                $query->addSelect(['total_inv_qty' => \App\Models\SalesInvoiceItem::selectRaw('SUM(qty)')
                    ->whereHas('invoice', function ($q) {
                        $q->whereColumn('sales_order_id', 'sales_orders.id')
                          ->whereIn('status', ['posted', 'draft']);
                    })
                ]);

                if ($status === 'not_invoiced') {
                    $query->havingRaw('COALESCE(total_inv_qty, 0) <= 0');
                } elseif ($status === 'partial') {
                    $query->havingRaw('COALESCE(total_inv_qty, 0) > 0 AND COALESCE(total_inv_qty, 0) < total_so_qty');
                } elseif ($status === 'invoiced_full') {
                    $query->havingRaw('COALESCE(total_inv_qty, 0) >= total_so_qty AND total_so_qty > 0');
                }
            }
        }

        $orders = $query->latest()->get();

        return view('erp.sales.orders.index', compact('orders'));
    }

    public function show($id)
    {
        $so = SalesOrder::with('items.product', 'customer')->findOrFail($id);

        $subtotal = $so->items()->sum('line_subtotal');
        $discountItem = $so->items()->sum('line_discount');

        $advancePaid = $so->getTotalAdvancePaid();

        $remaining = $so->grand_total - $advancePaid;
        $isPaid = $remaining <= 0;

        // 🔥 HITUNG PROGRESS INVOICE (BASED ON QTY) — draft invoice ikut dihitung sebagai "punya invoice"
        $totalSoQty = $so->items()->sum('qty');
        $totalInvoicedQty = \App\Models\SalesInvoiceItem::whereHas('invoice', function ($q) use ($so) {
            $q->where('sales_order_id', $so->id)
              ->whereIn('status', ['posted', 'draft']);
        })->sum('qty');

        if ($totalInvoicedQty >= $totalSoQty && $totalSoQty > 0) {
            $invoiceStatus = 'invoiced';
        } elseif ($totalInvoicedQty > 0 && $totalInvoicedQty < $totalSoQty) {
            $invoiceStatus = 'partial';
        } else {
            $invoiceStatus = 'not_invoiced';
        }

        // Simpan jumlah yang sudah di invoice untuk ditampilkan jika perlu (optional)
        $invoicedAmount = \App\Models\SalesInvoice::where('sales_order_id', $so->id)
            ->where('status', 'posted')
            ->sum('grand_total');

        $delivery = \App\Modules\Sales\Models\SalesDelivery::where('sales_order_id', $so->id)
            ->where('status', '!=', 'cancelled')
            ->first();

        return view('erp.sales.orders.show', compact(
            'so', 
            'subtotal', 
            'discountItem', 
            'advancePaid', 
            'remaining', 
            'isPaid', 
            'delivery',
            'invoiceStatus',
            'invoicedAmount'
        ));
    }

    public function create(Request $request)
    {
        $customers = Customer::all();
        $products = Product::with('units')->get();
        $warehouses = Warehouse::all();

        $draftQuotations = SalesQuotation::with('customer')
            ->where('status', 'draft')
            ->orderByDesc('id')
            ->get();

        $quotation = null;

        if ($request->quotation_id) {
            $quotation = SalesQuotation::with('items')
                ->where('status', '!=', 'converted')
                ->findOrFail($request->quotation_id);
        }

        $quotationId = $request->quotation_id ?? null;

        return view('erp.sales.orders.create', compact(
            'customers',
            'products',
            'warehouses',
            'draftQuotations',
            'quotation',
            'quotationId'
        ));
    }

    public function edit($id)
    {
        $so = SalesOrder::with('items.product.units')->findOrFail($id);

        return view('erp.sales.orders.edit', [
            'so' => $so,
            'customers' => Customer::all(),
            'warehouses' => Warehouse::all(),
            'products' => Product::with('units')->get(),
        ]);
    }

    public function createFromQuotation($quotationId)
    {
        $quotation = SalesQuotation::with('items.product')->findOrFail($quotationId);

        $customers = Customer::all();
        $warehouses = Warehouse::all();
        $products = Product::with('units')->get();

        return view('erp.sales.orders.create', compact(
            'quotation',
            'customers',
            'warehouses',
            'products'
        ));
    }

    public function store(Request $request)
    {
        // dd($request->all()); // Uncomment to debug form data
        $request->validate([
            'customer_id' => 'required_without:quotation_id|exists:customers,id',
            'warehouse_id' => 'required_without:quotation_id|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'expense' => 'nullable|numeric',
        ]);

        return DB::transaction(function () use ($request) {
            $date = $request->order_date ?? now()->toDateString();

            $customerId = $request->customer_id;
            $warehouseId = $request->warehouse_id;

            if ($request->filled('quotation_id')) {
                $exists = SalesOrder::where('quotation_id', $request->quotation_id)
                    ->whereNotIn('status', ['void', 'cancelled'])
                    ->exists();

                if ($exists) {
                    return redirect()
                        ->back()
                        ->with('error', 'Penawaran ini sudah pernah dibuat Sales Order.');
                }

                $quotation = SalesQuotation::findOrFail($request->quotation_id);
                $customerId = $quotation->customer_id;
                $warehouseId = $quotation->warehouse_id;
            }

            if (!$customerId) {
                throw new \Exception('Customer wajib dipilih.');
            }

            if (!$warehouseId) {
                throw new \Exception('Warehouse wajib dipilih.');
            }

            $customerPoNumber = trim((string) $request->customer_po_number) ?: null;

            $customerModel = Customer::find($customerId);
            if ($customerModel && $customerModel->is_marketplace && !$customerPoNumber) {
                throw new \Exception('No. Pesanan ' . $customerModel->name . ' wajib diisi (akan dipakai sebagai No. SO & Invoice).');
            }

            $deliveryMethod = $request->input('delivery_method', 'kurir');
            if (!array_key_exists($deliveryMethod, SalesOrder::DELIVERY_METHODS)) {
                $deliveryMethod = 'kurir';
            }
            // Tanggal pengambilan hanya relevan untuk Ambil di Toko.
            $pickupDate = $deliveryMethod === 'ambil_toko' ? ($request->input('pickup_date') ?: null) : null;

            $so = SalesOrder::create([
                'order_number' => NumberGeneratorService::forCustomer('SO', $customerId, $customerPoNumber),
                'customer_id' => $customerId,
                'customer_po_number' => $customerPoNumber,
                'quotation_id' => $request->filled('quotation_id') ? $request->quotation_id : null,
                'warehouse_id' => $warehouseId,
                'delivery_method' => $deliveryMethod,
                'pickup_date' => $pickupDate,
                'order_date' => $date,
                'notes' => $request->notes,
                'status' => SalesOrderStatus::DRAFT->value,
                'grand_total' => 0 // Temporary
            ]);

            if ($request->filled('quotation_id')) {
                $quotation = SalesQuotation::findOrFail($request->quotation_id);
                $quotation->update([
                    'status' => 'converted',
                    'sales_order_id' => $so->id
                ]);
            }

            $subtotal = 0;
            $totalItemDiscount = 0;

            foreach ($request->items as $item) {
                if (empty($item['product_id'])) continue;

                $qty = (float) ($item['qty'] ?? 0);
                $unitPrice = clean_number($item['unit_price'] ?? 0);

                $lineSubtotal = $qty * $unitPrice;

                $discountType = $item['discount_type'] ?? 'nominal';
                $discountValue = clean_number($item['discount_value'] ?? 0);

                if ($discountType == 'percent') {
                    $lineDiscount = $lineSubtotal * ($discountValue / 100);
                } else {
                    // Diskon nominal = PER-UNIT (konsisten dgn SalesInvoiceService:111).
                    // Sebelumnya diperlakukan per-baris → total beda saat qty>1.
                    $lineDiscount = $discountValue * $qty;
                }

                $lineTotal = $lineSubtotal - $lineDiscount;

                $unit = \App\Core\Inventory\ProductUnit::find($item['unit_id'] ?? null);

                SalesOrderItem::create([
                    'sales_order_id' => $so->id,
                    'product_id' => $item['product_id'],
                    'description' => trim((string) ($item['description'] ?? '')) ?: null,
                    'unit_id' => $unit?->id,
                    'unit_name' => $unit?->unit_name,
                    'conversion_to_base' => $unit?->conversion_to_base ?? 1,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_per_unit' => $qty > 0 ? $lineDiscount / $qty : 0,
                    'net_unit_price' => $unitPrice - ($qty > 0 ? $lineDiscount / $qty : 0),
                    'line_subtotal' => $lineSubtotal,
                    'line_discount' => $lineDiscount,
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $lineSubtotal;
                $totalItemDiscount += $lineDiscount;
            }

            $dpp = $subtotal - $totalItemDiscount;

            // GLOBAL
            $globalType = $request->global_discount_type ?? 'nominal';
            $globalValue = clean_number($request->global_discount_value ?? 0);

            if ($globalType === 'percent') {
                $globalDiscountAmount = $dpp * ($globalValue / 100);
            } else {
                $globalDiscountAmount = $globalValue;
            }

            $dpp = $dpp - $globalDiscountAmount;

            $ship = $this->resolveShipping($request, $deliveryMethod);
            $shipping = $ship['net'];
            $expense  = clean_number($request->selling_expense ?? 0);

            $ppnPercent = (float) ($request->ppn_percent ?? 0);
            $pphPercent = (float) ($request->pph_percent ?? 0);

            $ppnAmount = $dpp * ($ppnPercent / 100);
            $pphAmount = $dpp * ($pphPercent / 100);

            $grandTotal = $dpp + $ppnAmount - $pphAmount + $shipping + $expense;

            $so->update([
                'subtotal' => $subtotal,
                'discount_total' => $totalItemDiscount,
                'global_discount_type' => $globalType,
                'global_discount_value' => $globalValue,
                'global_discount_amount' => $globalDiscountAmount,
                'shipping_cost'   => $shipping,
                'shipping_gross'  => $ship['gross'],
                'shipping_discount_type'  => $ship['disc_type'],
                'shipping_discount_value' => $ship['disc_value'],
                'shipping_courier_code'   => $ship['courier_code'],
                'shipping_service_code'   => $ship['service_code'],
                'shipping_service_name'   => $ship['service_name'],
                'package_length'          => $ship['pkg_length'],
                'package_width'           => $ship['pkg_width'],
                'package_height'          => $ship['pkg_height'],
                'expense'         => $expense,
                'selling_expense' => $expense,
                'ppn_percent' => $ppnPercent,
                'ppn_amount' => $ppnAmount,
                'pph_percent' => $pphPercent,
                'pph_amount' => $pphAmount,
                'grand_total' => $grandTotal,
            ]);

            $action = $request->input('_after_save', '');
            if (in_array($action, ['post', 'print'], true)) {
                try {
                    app(SalesOrderService::class)->confirm($so->id);
                } catch (\Exception $e) {
                    return redirect(list_url('sales.orders.index'))
                        ->with('error', 'Sales Order tersimpan sebagai draft. Gagal post: ' . $e->getMessage());
                }
            }

            if ($action === 'print') {
                return redirect()->route('sales.orders.print', $so->id);
            }

            return redirect(list_url('sales.orders.index'))
                ->with('success', $action === 'post' ? 'Sales Order berhasil disimpan & dipost.' : 'Sales Order berhasil disimpan sebagai draft.');
        });
    }

    public function update(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $so = SalesOrder::findOrFail($id);

            // Update menghapus & membuat ulang item (ID baru). Pada SO yang sudah
            // dikonfirmasi hal ini me-reset qty_invoiced, meninggalkan reservasi stok
            // yatim, dan memutus link SalesInvoiceItem.sales_order_item_id. Karena itu
            // edit hanya diizinkan pada status draft — selain itu harus di-void dulu.
            if ($so->status !== SalesOrderStatus::DRAFT->value) {
                return back()->with('error', 'Sales Order yang sudah dikonfirmasi tidak dapat diedit. Lakukan Void terlebih dahulu bila ingin mengubah.');
            }

            $date = $request->order_date ?? now()->toDateString();

            $customerId = $request->customer_id;
            $warehouseId = $request->warehouse_id;

            if ($request->filled('quotation_id')) {
                $quotation = SalesQuotation::findOrFail($request->quotation_id);
                $customerId = $quotation->customer_id;
                $warehouseId = $quotation->warehouse_id;
            }

            $deliveryMethod = $request->input('delivery_method', $so->delivery_method ?? 'kurir');
            if (!array_key_exists($deliveryMethod, SalesOrder::DELIVERY_METHODS)) {
                $deliveryMethod = 'kurir';
            }
            // Tanggal pengambilan hanya relevan untuk Ambil di Toko.
            $pickupDate = $deliveryMethod === 'ambil_toko' ? ($request->input('pickup_date') ?: null) : null;

            $so->update([
                'customer_id' => $customerId,
                'customer_po_number' => $request->customer_po_number,
                'quotation_id' => $request->filled('quotation_id') ? $request->quotation_id : null,
                'warehouse_id' => $warehouseId,
                'delivery_method' => $deliveryMethod,
                'pickup_date' => $pickupDate,
                'order_date' => $date,
                'notes' => $request->notes,
            ]);

            // Hapus items lama
            $so->items()->delete();

            $subtotal = 0;
            $totalItemDiscount = 0;

            foreach ($request->items as $item) {
                $qty = (float) ($item['qty'] ?? 0);
                $unitPrice = clean_number($item['unit_price'] ?? 0);

                $lineSubtotal = $qty * $unitPrice;

                $discountType = $item['discount_type'] ?? 'nominal';
                $discountValue = clean_number($item['discount_value'] ?? 0);

                if ($discountType == 'percent') {
                    $lineDiscount = $lineSubtotal * ($discountValue / 100);
                } else {
                    // Diskon nominal = PER-UNIT (konsisten dgn SalesInvoiceService:111).
                    // Sebelumnya diperlakukan per-baris → total beda saat qty>1.
                    $lineDiscount = $discountValue * $qty;
                }

                $lineTotal = $lineSubtotal - $lineDiscount;

                $unit = \App\Core\Inventory\ProductUnit::find($item['unit_id'] ?? null);

                SalesOrderItem::create([
                    'sales_order_id' => $so->id,
                    'product_id' => $item['product_id'],
                    'description' => trim((string) ($item['description'] ?? '')) ?: null,
                    'unit_id' => $unit?->id,
                    'unit_name' => $unit?->unit_name,
                    'conversion_to_base' => $unit?->conversion_to_base ?? 1,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_per_unit' => $qty > 0 ? $lineDiscount / $qty : 0,
                    'net_unit_price' => $unitPrice - ($qty > 0 ? $lineDiscount / $qty : 0),
                    'line_subtotal' => $lineSubtotal,
                    'line_discount' => $lineDiscount,
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $lineSubtotal;
                $totalItemDiscount += $lineDiscount;
            }

            $dpp = $subtotal - $totalItemDiscount;

            // GLOBAL
            $globalType = $request->global_discount_type ?? 'nominal';
            $globalValue = clean_number($request->global_discount_value ?? 0);

            if ($globalType === 'percent') {
                $globalDiscountAmount = $dpp * ($globalValue / 100);
            } else {
                $globalDiscountAmount = $globalValue;
            }

            $dpp = $dpp - $globalDiscountAmount;

            $request->validate([
                'expense' => 'nullable|numeric',
            ]);

            $ship = $this->resolveShipping($request, $deliveryMethod);
            $shipping = $ship['net'];
            $expense  = clean_number($request->selling_expense ?? 0);

            $ppnPercent = (float) ($request->ppn_percent ?? 0);
            $pphPercent = (float) ($request->pph_percent ?? 0);

            $ppnAmount = $dpp * ($ppnPercent / 100);
            $pphAmount = $dpp * ($pphPercent / 100);

            $grandTotal = $dpp + $ppnAmount - $pphAmount + $shipping + $expense;

            $so->update([
                'subtotal' => $subtotal,
                'discount_total' => $totalItemDiscount,
                'global_discount_type' => $globalType,
                'global_discount_value' => $globalValue,
                'global_discount_amount' => $globalDiscountAmount,
                'shipping_cost'   => $shipping,
                'shipping_gross'  => $ship['gross'],
                'shipping_discount_type'  => $ship['disc_type'],
                'shipping_discount_value' => $ship['disc_value'],
                'shipping_courier_code'   => $ship['courier_code'],
                'shipping_service_code'   => $ship['service_code'],
                'shipping_service_name'   => $ship['service_name'],
                'package_length'          => $ship['pkg_length'],
                'package_width'           => $ship['pkg_width'],
                'package_height'          => $ship['pkg_height'],
                'expense'         => $expense,
                'selling_expense' => $expense,
                'ppn_percent' => $ppnPercent,
                'ppn_amount' => $ppnAmount,
                'pph_percent' => $pphPercent,
                'pph_amount' => $pphAmount,
                'grand_total' => $grandTotal,
            ]);

            $action = $request->input('_after_save', '');
            if (in_array($action, ['post', 'print'], true) && $so->status === SalesOrderStatus::DRAFT->value) {
                try {
                    app(SalesOrderService::class)->confirm($so->id);
                } catch (\Exception $e) {
                    return redirect(list_url('sales.orders.index'))
                        ->with('error', 'Sales Order tersimpan. Gagal post: ' . $e->getMessage());
                }
            }

            if ($action === 'print') {
                return redirect()->route('sales.orders.print', $so->id);
            }

            return redirect(list_url('sales.orders.index'))
                ->with('success', 'Sales Order berhasil diperbarui.');
        });
    }

    /**
     * Resolusi ongkir dari form embed: net = gross - diskon (otoritatif server).
     * Ambil di toko → semua 0/null.
     * @return array{gross:float, disc_type:string, disc_value:float, net:float, courier_code:?string, service_name:?string}
     */
    private function resolveShipping(Request $request, string $deliveryMethod): array
    {
        if ($deliveryMethod === 'ambil_toko') {
            return ['gross' => 0, 'disc_type' => 'nominal', 'disc_value' => 0, 'net' => 0, 'courier_code' => null, 'service_code' => null, 'service_name' => null,
                    'pkg_length' => null, 'pkg_width' => null, 'pkg_height' => null];
        }

        $gross    = clean_number($request->shipping_gross ?? $request->shipping_cost ?? 0);
        $discType = in_array($request->shipping_discount_type, ['nominal', 'percent'], true) ? $request->shipping_discount_type : 'nominal';
        $discVal  = clean_number($request->shipping_discount_value ?? 0);
        $discAmt  = $discType === 'percent' ? $gross * ($discVal / 100) : $discVal;
        $net      = max(0, $gross - $discAmt);
        $dim      = fn ($v) => ($v !== null && (float) clean_number($v) > 0) ? (float) clean_number($v) : null;

        return [
            'gross'        => $gross,
            'disc_type'    => $discType,
            'disc_value'   => $discVal,
            'net'          => $net,
            'courier_code' => ($request->shipping_courier_code ?: null),
            'service_code' => ($request->shipping_service_code ?: null),
            'service_name' => ($request->shipping_service_name ?: null),
            'pkg_length'   => $dim($request->package_length),
            'pkg_width'    => $dim($request->package_width),
            'pkg_height'   => $dim($request->package_height),
        ];
    }

    public function confirm($id)
    {
        app(SalesOrderService::class)->confirm($id);

        return redirect()->route('sales.orders.show', $id)
            ->with('success', 'Sales Order berhasil dipost');
    }

    /**
     * Konfirmasi pengambilan barang di toko (Ambil di Toko).
     * Verifikasi booking code → terbit + post Surat Jalan (full sisa) → tandai diambil.
     */
    public function pickup(Request $request, $id, \App\Modules\Sales\Services\SalesDeliveryService $deliveryService)
    {
        $so = SalesOrder::findOrFail($id);

        if ($so->delivery_method !== 'ambil_toko') {
            return back()->with('error', 'SO ini bukan metode Ambil di Toko.');
        }
        if ($so->status !== SalesOrderStatus::CONFIRMED->value) {
            return back()->with('error', 'SO harus dikonfirmasi (post) dulu sebelum barang bisa diambil.');
        }
        if ($so->pickup_status === 'picked_up') {
            return back()->with('error', 'Barang SO ini sudah tercatat diambil.');
        }

        // Verifikasi booking code (staff memasukkan kode yang disebut customer).
        $input = strtoupper(trim((string) $request->input('pickup_code', '')));
        if ($input === '' || $input !== strtoupper((string) $so->pickup_code)) {
            return back()->with('error', 'Booking code tidak cocok. Periksa kembali kode yang diberikan customer.');
        }

        try {
            $delivery = DB::transaction(function () use ($so, $deliveryService) {
                $d = $deliveryService->createFromOrder($so, 'ambil_toko');
                if ($d) {
                    $deliveryService->post($d->id);
                }
                $so->update([
                    'pickup_status' => 'picked_up',
                    'picked_up_at'  => now(),
                ]);
                return $d;
            });
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal memproses pengambilan: ' . $e->getMessage());
        }

        if ($delivery) {
            return redirect()->route('sales.deliveries.show', $delivery->id)
                ->with('success', "Pengambilan dikonfirmasi. Surat Jalan {$delivery->delivery_number} terbit & diposting.");
        }

        return redirect()->route('sales.orders.show', $so->id)
            ->with('success', 'Pengambilan dikonfirmasi (barang sudah dikirim sebelumnya).');
    }

    /** Edit cepat Tanggal Pengambilan (Ambil di Toko) langsung dari halaman SO. */
    public function updatePickupDate(Request $request, $id)
    {
        $so = SalesOrder::findOrFail($id);

        if ($so->delivery_method !== 'ambil_toko') {
            return back()->with('error', 'SO ini bukan metode Ambil di Toko.');
        }

        $data = $request->validate([
            'pickup_date' => ['nullable', 'date'],
        ]);

        $so->update(['pickup_date' => $data['pickup_date'] ?: null]);

        return back()->with('success', 'Tanggal pengambilan diperbarui.');
    }

    public function void($id)
    {
        $so = SalesOrder::findOrFail($id);

        if ($so->status !== 'confirmed') {
            return back()->with('error', 'Hanya SO confirmed yang bisa di-void');
        }

        // ── Dependency checks: tolak void bila masih ada dokumen turunan aktif ──
        $activeInvoice = \App\Models\SalesInvoice::where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->first();
        if ($activeInvoice) {
            return back()->with('error', "SO tidak bisa di-void: masih ada Invoice {$activeInvoice->invoice_number} aktif. Void invoice tersebut terlebih dahulu.");
        }

        $activeDelivery = \App\Modules\Sales\Models\SalesDelivery::where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->first();
        if ($activeDelivery) {
            return back()->with('error', "SO tidak bisa di-void: masih ada Surat Jalan {$activeDelivery->delivery_number} aktif. Void surat jalan tersebut terlebih dahulu.");
        }

        $activePayment = \App\Models\CustomerPaymentAllocation::where('sales_order_id', $so->id)
            ->whereHas('payment', fn($q) => $q->where('status', 'posted'))
            ->with('payment')
            ->first();
        if ($activePayment) {
            $payNum = $activePayment->payment->payment_number ?? '#' . $activePayment->customer_payment_id;
            return back()->with('error', "SO tidak bisa di-void: masih ada Payment {$payNum} aktif. Void payment tersebut terlebih dahulu.");
        }

        $activeReturn = \App\Modules\Sales\Models\SalesReturn::where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])
            ->first();
        if ($activeReturn) {
            return back()->with('error', "SO tidak bisa di-void: masih ada Retur {$activeReturn->return_number} aktif. Void retur tersebut terlebih dahulu.");
        }

        // Release stock reservations
        \App\Core\Inventory\StockReservation::where('sales_order_id', $so->id)
            ->update(['status' => 'cancelled']);

        $this->cancelAutoPreorderProductions($so);

        $so->status = 'void';
        $so->save();

        // Kalau SO ini berasal dari Quotation, kembalikan status Quotation ke
        // 'draft' agar bisa di-convert ulang. Skip kalau masih ada SO lain
        // yang aktif merujuk ke Quotation yang sama.
        if ($so->quotation_id) {
            $stillReferenced = SalesOrder::where('quotation_id', $so->quotation_id)
                ->whereNotIn('status', ['void', 'cancelled'])
                ->where('id', '!=', $so->id)
                ->exists();
            if (!$stillReferenced) {
                \App\Models\SalesQuotation::where('id', $so->quotation_id)
                    ->where('status', 'converted')
                    ->update(['status' => 'draft']);
            }
        }

        return back()->with('success', 'SO berhasil di-void');
    }

    /**
     * PO produksi auto preorder yang masih draft → cancel.
     * Yang sudah confirmed/in_progress/completed → biarkan jalan (material sudah dikonsumsi),
     * cukup log warning agar bisa di-review manual.
     */
    private function cancelAutoPreorderProductions(SalesOrder $so): void
    {
        $pos = ProductionOrder::where('sales_order_id', $so->id)
            ->where('created_via', 'auto_preorder')
            ->whereNotIn('status', ['cancelled', 'finalized'])
            ->get();

        foreach ($pos as $po) {
            if ($po->status === 'draft') {
                $po->update([
                    'status' => 'cancelled',
                    'notes'  => trim(($po->notes ?? '') . "\n[Auto-cancel: SO {$so->order_number} di-void]"),
                ]);
            } else {
                Log::warning('PO auto_preorder tidak di-cancel karena sudah dikerjakan', [
                    'production_order_id' => $po->id,
                    'order_number'        => $po->order_number,
                    'status'              => $po->status,
                    'sales_order_id'      => $so->id,
                ]);
            }
        }
    }

    public function print($id)
    {
        $order = SalesOrder::with(['customer', 'warehouse', 'items.product', 'advances'])->findOrFail($id);

        if ($order->status === 'void') {
            return redirect()->route('sales.orders.show', $order->id)
                ->with('error', 'Sales Order sudah di-void dan tidak boleh dicetak.');
        }

        $this->ensurePaymentLink($order);
        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        return view('erp.sales.orders.print', compact('order', 'profile'));
    }

    /**
     * Pastikan SO punya link pembayaran Midtrans aktif (untuk QR di Print SO).
     * Hanya untuk SO confirmed yang belum lunas. Cuma insert lokal (tanpa panggil API),
     * idempotent (reuse link yang masih aktif). Tidak boleh menggagalkan proses cetak.
     */
    private function ensurePaymentLink(SalesOrder $order): void
    {
        $remaining = (float) $order->grand_total - (float) ($order->paid_amount ?? 0);
        if ($order->status !== 'confirmed' || $remaining <= 0) {
            return;
        }
        try {
            app(\App\Modules\Payment\Services\PaymentLinkService::class)
                ->getOrCreateForSalesOrder($order, auth()->id());
        } catch (\Throwable $e) {
            // Abaikan — kalau gagal, Print SO tetap tampil tanpa QR.
        }
    }

    /** Cetak banyak SO sekaligus dalam 1 halaman (page-break per dokumen). */
    public function printBulk(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($v) => (int) trim($v))->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return redirect()->route('sales.orders.index')->with('error', 'Tidak ada pesanan yang dipilih untuk dicetak.');
        }

        $orders = SalesOrder::with(['customer', 'warehouse', 'items.product', 'advances'])
            ->whereIn('id', $ids)
            ->where('status', '!=', 'void')
            ->get()
            ->sortBy(fn ($o) => $ids->search($o->id))
            ->values();

        if ($orders->isEmpty()) {
            return redirect()->route('sales.orders.index')->with('error', 'Pesanan terpilih tidak dapat dicetak.');
        }

        $profile  = \App\Models\BusinessProfile::instance()->load('bankAccounts');
        $indexUrl = url()->previous() ?: route('sales.orders.index');

        return view('erp.sales.orders.print-bulk', compact('orders', 'profile', 'indexUrl'));
    }

    public function downloadPdf($id)
    {
        $order = SalesOrder::with(['customer', 'warehouse', 'items.product', 'advances'])->findOrFail($id);

        if ($order->status === 'void') {
            return redirect()->route('sales.orders.show', $order->id)
                ->with('error', 'Sales Order sudah di-void dan tidak boleh dicetak.');
        }

        $this->ensurePaymentLink($order);
        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        $html = view('erp.sales.orders.print', [
            'order'   => $order,
            'profile' => $profile,
            'pdfMode' => true,
        ])->render();

        // Inline aset lokal jadi base64 → hindari deadlock single-worker `php artisan serve`.
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

        $filename = 'PesananPenjualan_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $order->order_number) . '.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function updateNotes(Request $request, $id)
    {
        $request->validate(['notes' => 'nullable|string|max:2000']);

        $so = SalesOrder::findOrFail($id);
        $so->notes = $request->notes;
        $so->save();

        return response()->json(['success' => true]);
    }


    public function showApi($id)
    {
        // Load DUA relasi bundle: bundleComponents (tabel bundle_components, qty)
        // dan bundleItems (tabel product_bundles, qty_required) — data bisa ada di salah satunya.
        $so = SalesOrder::with([
            'customer',
            'items.product.bundleComponents.componentProduct',
            'items.product.bundleItems.componentProduct',
        ])->findOrFail($id);

        $items = [];
        foreach ($so->items as $item) {
            // 🔥 HITUNG SISA QTY (ERP STANDARD)
            $qtyInvoiced = \App\Models\SalesInvoiceItem::whereHas('invoice', function ($q) use ($so) {
                $q->where('sales_order_id', $so->id)
                  ->where('status', 'posted');
            })
            ->where('sales_order_item_id', $item->id)
            ->sum('qty');

            $remainingQty = (float)($item->qty - $qtyInvoiced);

            if ($remainingQty <= 0) continue; // Skip if fully invoiced

            $product = $item->product;

            $row = [
                'id' => $item->id, // legacy
                'sales_order_item_id' => $item->id, // 🔥 MANDATORY FOR INVOICE MAPPING
                'product_id' => $product->id,
                'sku' => $product->sku ?? '-',
                'name' => $product->name ?? '-',
                'description' => $item->description ?? $product->name ?? '-',
                'qty' => $remainingQty,
                'price' => (float)($item->unit_price ?? $item->price ?? 0),
                'discount_type' => $item->discount_type ?? 'percent',
                'discount_value' => (float)($item->discount_value ?? 0),
                'type' => $product->type,
                'is_bundle_component' => false,
                'components' => []
            ];

            if ($product->type === 'bundle') {
                // Prioritas: bundle_components (kolom qty). Fallback: product_bundles (kolom qty_required).
                $bundleRows = $product->bundleComponents->isNotEmpty()
                    ? $product->bundleComponents
                    : $product->bundleItems;

                foreach ($bundleRows as $bundle) {
                    $component = $bundle->componentProduct;
                    if (!$component) continue;
                    // BundleComponent pakai 'qty', ProductBundle pakai 'qty_required'.
                    $perBundle = (float) ($bundle->qty_required ?? $bundle->qty ?? 0);
                    $row['components'][] = [
                        'product_id'   => $component->id,
                        'sku'          => $component->sku ?? '-',
                        'name'         => $component->name ?? '-',
                        'qty_required' => $perBundle,
                        'qty'          => $perBundle * $remainingQty,
                    ];
                }
            }

            $items[] = $row;
        }

        return response()->json([
            'id' => $so->id,
            'customer_id' => $so->customer_id,
            'customer_name' => $so->customer->name,
            'warehouse_id' => $so->warehouse_id,
            'delivery_method' => $so->delivery_method ?? 'kurir',
            'customer_po_number' => $so->customer_po_number,
            'global_discount_type' => $so->global_discount_type ?? 'nominal',
            'global_discount_value' => (float)($so->global_discount_value ?? 0),
            'shipping_cost' => (float)($so->shipping_cost ?? 0),
            'shipping_gross' => (float)($so->shipping_gross ?? 0),
            'shipping_discount_type' => $so->shipping_discount_type ?? 'nominal',
            'shipping_discount_value' => (float)($so->shipping_discount_value ?? 0),
            'shipping_courier_code' => $so->shipping_courier_code,
            'shipping_service_code' => $so->shipping_service_code,
            'shipping_service_name' => $so->shipping_service_name,
            'package_length' => $so->package_length,
            'package_width'  => $so->package_width,
            'package_height' => $so->package_height,
            'expense' => (float)($so->expense ?? 0),
            'ppn_percent' => (float)($so->ppn_percent ?? 0),
            'advance' => (float) $so->getTotalAdvancePaid(),
            'delivery' => $so->deliveries->first(),
            'items' => $items
        ]);
    }

}
