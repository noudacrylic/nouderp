<?php

namespace App\Http\Controllers;

use App\Modules\Sales\Models\SalesOrder;
use App\Enums\SalesOrderStatus;
use App\DTO\SalesInvoiceDTO;
use App\DTO\SalesInvoiceItemDTO;
use App\Modules\Sales\Services\SalesInvoiceService;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Core\Inventory\Warehouse;
use App\Core\Inventory\Product;
use App\Http\Controllers\Concerns\InlinesPrintAssets;

class DebugInvoiceController extends Controller
{
    use InlinesPrintAssets;

    private function isVoidInvoice(\App\Models\SalesInvoice $invoice): bool
    {
        $status = $invoice->status instanceof \App\Enums\InvoiceStatusEnum
            ? $invoice->status->value
            : $invoice->status;

        return $status === 'void';
    }

    public function index(Request $request)
    {
        $query = \App\Models\SalesInvoice::with('customer')
            ->withExists('returns as has_return')
            ->withExists('warrantyOrders as has_warranty');

        // Subquery agregat: total qty invoice, total qty diretur (status posted),
        // dan jumlah invoice sibling yang share SO yang sama (untuk deteksi partial).
        $query->addSelect([
            'items_qty_total' => \App\Models\SalesInvoiceItem::selectRaw('COALESCE(SUM(qty), 0)')
                ->whereColumn('sales_invoice_id', 'sales_invoices.id'),
            'items_returned_total' => \DB::table('sales_return_items')
                ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
                ->selectRaw('COALESCE(SUM(sales_return_items.qty), 0)')
                ->whereColumn('sales_returns.invoice_id', 'sales_invoices.id')
                ->where('sales_returns.status', 'posted'),
            'so_sibling_count' => \DB::table('sales_invoices as si_sib')
                ->selectRaw('COUNT(*)')
                ->whereColumn('si_sib.sales_order_id', 'sales_invoices.sales_order_id')
                ->whereNotNull('si_sib.sales_order_id')
                ->where('si_sib.status', '<>', 'void'),
        ]);

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%$search%")
                    ->orWhereHas('customer', function ($c) use ($search) {
                        $c->where('name', 'like', "%$search%");
                    });
            });
        }

        if ($request->date_from) {
            $query->whereDate('invoice_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('invoice_date', '<=', $request->date_to);
        }

        if ($request->marketplace === 'marketplace') {
            $query->whereHas('customer', fn ($c) => $c->where('is_marketplace', true));
        } elseif ($request->marketplace === 'non_marketplace') {
            $query->whereHas('customer', fn ($c) => $c->where('is_marketplace', false));
        }

        // FILTER UMUR FAKTUR BELUM DIPROSES — selaras dengan age-badge (belum lunas & bukan void).
        // Bucket: < 1 minggu | 1 minggu–1 bulan | 1 bulan–3 bulan | > 3 bulan (umur dari invoice_date).
        if ($request->age) {
            $today = \Carbon\Carbon::today();
            $w1 = $today->copy()->subWeek();      // batas 1 minggu
            $m1 = $today->copy()->subMonth();     // batas 1 bulan
            $m3 = $today->copy()->subMonths(3);   // batas 3 bulan

            // Hanya yang "belum diproses": posted & belum lunas (sisa tagihan > 0).
            $query->where('status', 'posted')
                ->whereRaw('grand_total - IFNULL(paid_amount, 0) - IFNULL(advance_applied, 0) > 0.01');

            if ($request->age === 'lt_1w') {
                $query->whereDate('invoice_date', '>=', $w1);
            } elseif ($request->age === '1w_1m') {
                $query->whereDate('invoice_date', '<', $w1)->whereDate('invoice_date', '>=', $m1);
            } elseif ($request->age === '1m_3m') {
                $query->whereDate('invoice_date', '<', $m1)->whereDate('invoice_date', '>=', $m3);
            } elseif ($request->age === 'gt_3m') {
                $query->whereDate('invoice_date', '<', $m3);
            }
        }

        if ($request->status) {
            match ($request->status) {
                'draft'            => $query->where('status', 'draft'),
                'belum_lunas'      => $query->where('status', 'posted')
                                            ->whereRaw('grand_total - IFNULL(paid_amount, 0) - IFNULL(advance_applied, 0) > 0.01'),
                'selesai'          => $query->where('status', 'posted')
                                            ->whereRaw('grand_total - IFNULL(paid_amount, 0) - IFNULL(advance_applied, 0) <= 0.01'),
                'void'             => $query->where('status', 'void'),
                'returned_partial' => $query->where('status', 'posted')
                                            ->havingRaw('items_returned_total > 0 AND items_returned_total < items_qty_total'),
                'returned_full'    => $query->where('status', 'posted')
                                            ->havingRaw('items_returned_total >= items_qty_total AND items_qty_total > 0'),
                default            => null,
            };
        }

        $invoices = $query->orderByDesc('id')->get();

        if ($request->ajax()) {
            return view('erp.sales.invoices.partials.table_rows', compact('invoices'))->render();
        }

        return view('erp.sales.invoices.index', compact('invoices'));
    }

    public function show($id)
    {
        $invoice = \App\Models\SalesInvoice::with('items.product')->findOrFail($id);

        return view('erp.sales.invoices.show', compact('invoice'));
    }

    public function print($id)
    {
        $invoice = \App\Models\SalesInvoice::with(['customer', 'items.product'])->findOrFail($id);

        if ($this->isVoidInvoice($invoice)) {
            return redirect()->route('sales.invoices.show', $invoice->id)
                ->with('error', 'Faktur sudah di-void dan tidak boleh dicetak.');
        }

        $this->ensurePaymentLink($invoice);
        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');
        return view('erp.sales.invoices.print', compact('invoice', 'profile'));
    }

    /**
     * Pastikan invoice punya link pembayaran Midtrans aktif (untuk QR di Print Invoice).
     * Hanya untuk invoice posted yang belum lunas. Cuma insert lokal (tanpa panggil API),
     * idempotent. Tidak boleh menggagalkan proses cetak.
     */
    private function ensurePaymentLink(\App\Models\SalesInvoice $invoice): void
    {
        $statusVal = $invoice->status instanceof \App\Enums\InvoiceStatusEnum
            ? $invoice->status->value : $invoice->status;
        if ($statusVal !== 'posted' || (float) $invoice->remaining_amount <= 0) {
            return;
        }
        try {
            app(\App\Modules\Payment\Services\PaymentLinkService::class)
                ->getOrCreateForInvoice($invoice, auth()->id());
        } catch (\Throwable $e) {
            // Abaikan — kalau gagal, Print Invoice tetap tampil tanpa QR.
        }
    }

    public function downloadPdf($id)
    {
        $invoice = \App\Models\SalesInvoice::with(['customer', 'items.product'])->findOrFail($id);

        if ($this->isVoidInvoice($invoice)) {
            return redirect()->route('sales.invoices.show', $invoice->id)
                ->with('error', 'Faktur sudah di-void dan tidak boleh dicetak.');
        }

        $this->ensurePaymentLink($invoice);
        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        $html = view('erp.sales.invoices.print', [
            'invoice' => $invoice,
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
        $filename = 'FakturPenjualan_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $invoice->invoice_number) . '.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
    public function create(Request $request)
    {
        // Tombol "+ Invoice" di Sales Order mengirim query ?sales_order_id=, link lain pakai ?so=.
        // Kalau salah satu dipasok dan SO valid, langsung delegasi ke createFromSO supaya
        // header (customer, warehouse, no PO) + items + uang muka + delivery ter-prefill.
        $soId = $request->input('sales_order_id') ?? $request->input('so');
        if ($soId) {
            $soModel = \App\Modules\Sales\Models\SalesOrder::find($soId);
            if (!$soModel) {
                return redirect()->back()->with('error', 'Sales Order tidak ditemukan.');
            }
            if ($soModel->invoices()->exists()) {
                return redirect()->back()->with('error', 'Sales Order ini sudah memiliki invoice.');
            }
            return $this->createFromSO($soModel->id);
        }
        $so = null;
        $items = collect();
        $delivery = null;
        $totalAdvance = 0;
        $remainingAdvance = 0;

        $global_discount_type = 'nominal';
        $global_discount_value = 0;
        $ppn_percent = 0;
        $pph_percent = 0;
        $shipping_cost = 0;

        $customers = Customer::all();
        $warehouses = Warehouse::all();
        $products = Product::all();

        $salesOrders = SalesOrder::where('status', SalesOrderStatus::CONFIRMED->value)
            ->whereDoesntHave('invoices')
            ->with('customer')
            ->latest()
            ->get();

        return view('erp.sales.invoices.create', compact(
            'so',
            'items',
            'delivery',
            'totalAdvance',
            'remainingAdvance',
            'global_discount_type',
            'global_discount_value',
            'ppn_percent',
            'pph_percent',
            'shipping_cost',
            'customers',
            'warehouses',
            'products',
            'salesOrders'
        ));
    }

    public function createFromSO($soId)
    {
        $so = \App\Modules\Sales\Models\SalesOrder::with([
            'items.product',
            'customer',
            'warehouse'
        ])->findOrFail($soId);

        if ($so->invoices()->exists()) {
            return redirect()
                ->back()
                ->with('error', 'Sales Order ini sudah memiliki invoice.');
        }

        $delivery = \App\Modules\Sales\Models\SalesDelivery::where('reference_id', $so->id)
            ->where('reference_type', 'sales_order')
            ->first();

        // ðŸ”¥ TAMBAHKAN INI (Step 6)
        $deliveries = \App\Modules\Sales\Models\SalesDelivery::where('sales_order_id', $so->id)
            ->where('status', 'posted')
            ->get();

        $advances = \App\Modules\Sales\Models\SalesAdvance::where('sales_order_id', $so->id)
            ->where('status', 'posted')
            ->get();

        $totalAdvance = $advances->sum('amount');
        $remainingAdvance = $totalAdvance;

        // ðŸ”¥ CALCULATE REMAINING QTY FOR EACH ITEM
        $items = collect();
        foreach ($so->items as $item) {
            $qtyInvoiced = \App\Models\SalesInvoiceItem::whereHas('invoice', function ($q) use ($so) {
                $q->where('sales_order_id', $so->id)
                  ->where('status', 'posted');
            })
            ->where('sales_order_item_id', $item->id) // Cocokkan dengan ID baris SO
            ->sum('qty');

            $remainingQty = $item->qty - $qtyInvoiced;

            if ($remainingQty > 0) {
                // Gunakan sisa qty untuk tampilan di form
                $item->qty = $remainingQty;
                $items->push($item);
            }
        }

        if ($items->isEmpty()) {
            return redirect()
                ->back()
                ->with('error', 'Semua barang dalam Sales Order ini sudah ditagihkan (Full Invoiced).');
        }

        // ðŸ”¥ Master Data for Modular Components
        $customers = \App\Models\Customer::all();
        $warehouses = \App\Core\Inventory\Warehouse::all();
        $products = \App\Core\Inventory\Product::all();

        $salesOrders = SalesOrder::where('status', SalesOrderStatus::CONFIRMED->value)
            ->whereDoesntHave('invoices')
            ->with('customer')
            ->latest()
            ->get();

        // Default values for invoice header
        $global_discount_type = 'nominal';
        $global_discount_value = 0;
        $shipping_cost = 0;
        $notes = '';

        return view('erp.sales.invoices.create', compact(
            'so',
            'delivery',
            'deliveries',
            'advances',
            'totalAdvance',
            'remainingAdvance',
            'items',
            'global_discount_type',
            'global_discount_value',
            'shipping_cost',
            'notes',
            'customers',
            'warehouses',
            'products',
            'salesOrders'
        ));
    }

    /** Resolusi ongkir invoice: net = gross - diskon. Ambil di toko → 0. */
    private function resolveInvoiceShipping(Request $request, string $deliveryMethod): array
    {
        if ($deliveryMethod === 'ambil_toko') {
            return ['gross' => 0, 'disc_type' => 'nominal', 'disc_value' => 0, 'net' => 0, 'courier_code' => null, 'service_code' => null, 'service_name' => null,
                    'pkg_length' => null, 'pkg_width' => null, 'pkg_height' => null];
        }
        $gross    = clean_number($request->shipping_gross ?? $request->shipping_cost ?? 0);
        $discType = in_array($request->shipping_discount_type, ['nominal', 'percent'], true) ? $request->shipping_discount_type : 'nominal';
        $discVal  = clean_number($request->shipping_discount_value ?? 0);
        $discAmt  = $discType === 'percent' ? $gross * ($discVal / 100) : $discVal;
        $dim      = fn ($v) => ($v !== null && (float) clean_number($v) > 0) ? (float) clean_number($v) : null;

        return [
            'gross'        => $gross,
            'disc_type'    => $discType,
            'disc_value'   => $discVal,
            'net'          => max(0, $gross - $discAmt),
            'courier_code' => ($request->shipping_courier_code ?: null),
            'service_code' => ($request->shipping_service_code ?: null),
            'service_name' => ($request->shipping_service_name ?: null),
            'pkg_length'   => $dim($request->package_length),
            'pkg_width'    => $dim($request->package_width),
            'pkg_height'   => $dim($request->package_height),
        ];
    }

    public function store(Request $request, SalesInvoiceService $service)
    {


        $items = [];

        foreach ($request->items as $item) {
            $items[] = new SalesInvoiceItemDTO(
                $item['sales_order_item_id'] ?? null,
                $item['product_id'] ?? null,
                $item['description'] ?? '',
                'product',
                (float) ($item['qty'] ?? 0),
                clean_number($item['unit_price'] ?? 0),
                $item['discount_type'] ?? 'nominal',
                clean_number($item['discount_value'] ?? 0),
                clean_number($item['discount_amount'] ?? 0),
                (float) ($item['ppn_percent'] ?? $request->ppn_percent ?? 0),
                (float) ($item['pph_percent'] ?? $request->pph_percent ?? 0),
            );
        }

        $invoiceMethod = in_array($request->delivery_method, ['kurir', 'instant', 'ambil_toko'], true)
            ? $request->delivery_method
            : 'kurir';
        $ship = $this->resolveInvoiceShipping($request, $invoiceMethod);

        $dto = new SalesInvoiceDTO(
            $request->sales_order_id ? (int) $request->sales_order_id : null,
            (int) $request->customer_id,
            (int) $request->warehouse_id,
            $request->invoice_date,
            $request->global_discount_type,
            clean_number($request->global_discount_value),
            (float) $request->ppn_percent,
            (float) $request->pph_percent,
            $ship['net'],
            clean_number($request->additional_fee),
            clean_number($request->advance_applied),
            $request->notes,
            $items
        );

        try {
            $invoice = $service->createDraft($dto);
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Gagal membuat invoice: ' . $e->getMessage());
        }

        $invoice->update([
            'delivery_method'         => $invoiceMethod,
            'shipping_gross'          => $ship['gross'],
            'shipping_discount_type'  => $ship['disc_type'],
            'shipping_discount_value' => $ship['disc_value'],
            'shipping_courier_code'   => $ship['courier_code'],
            'shipping_service_code'   => $ship['service_code'],
            'package_length'          => $ship['pkg_length'],
            'package_width'           => $ship['pkg_width'],
            'package_height'          => $ship['pkg_height'],
            'shipping_service_name'   => $ship['service_name'],
        ]);

        $action = $request->input('_after_save', '');
        if (in_array($action, ['post', 'print'], true)) {
            try {
                app(\App\Services\InvoicePostingService::class)->post($invoice);
            } catch (\Exception $e) {
                return redirect()->route('sales.invoices.index')
                    ->with('error', 'Invoice tersimpan sebagai draft. Gagal post: ' . $e->getMessage());
            }
        }

        if ($action === 'print') {
            return redirect()->route('sales.invoices.print', $invoice->id);
        }

        return redirect()->route('sales.invoices.index')
            ->with('success', $action === 'post' ? 'Invoice berhasil dipost.' : 'Invoice draft tersimpan.');
    }

    public function post($id, \App\Services\InvoicePostingService $service)
    {
        $invoice = \App\Models\SalesInvoice::findOrFail($id);

        if ($invoice->status !== \App\Enums\InvoiceStatusEnum::DRAFT) {
            return back()->with('error', 'Invoice sudah dipost atau dibatalkan.');
        }

        try {
            $service->post($invoice);
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal posting: ' . $e->getMessage());
        }

        return redirect()->route('sales.invoices.show', $invoice->id)
            ->with('success', 'Invoice berhasil dipost.');
    }

    public function cancel($id)
    {
        $invoice = \App\Models\SalesInvoice::findOrFail($id);

        if ($invoice->status !== \App\Enums\InvoiceStatusEnum::DRAFT) {
            return back()->with('error', 'Hanya invoice berstatus draft yang dapat dibatalkan.');
        }

        $invoice->status = \App\Enums\InvoiceStatusEnum::VOID; // Or cancelled if you have that enum
        $invoice->save();

        return redirect()->route('sales.invoices.show', $invoice->id)
            ->with('success', 'Invoice berhasil dibatalkan.');
    }

    public function void($id)
    {
        $invoice = \App\Models\SalesInvoice::with(['items.product', 'delivery'])->findOrFail($id);

        $statusVal = $invoice->status instanceof \App\Enums\InvoiceStatusEnum
            ? $invoice->status->value
            : $invoice->status;

        if (!in_array($statusVal, ['posted', 'paid'], true)) {
            return back()->with('error', 'Hanya invoice berstatus posted/paid yang dapat di-void.');
        }

        // -- Dependency checks ------------------------------------------
        $activePayment = \App\Models\CustomerPaymentAllocation::where('invoice_id', $invoice->id)
            ->whereHas('payment', fn($q) => $q->where('status', 'posted'))
            ->with('payment')
            ->first();
        if ($activePayment) {
            $payNum = $activePayment->payment->payment_number ?? '#' . $activePayment->customer_payment_id;
            return back()->with('error', "Invoice tidak bisa di-void: masih ada Payment {$payNum} aktif. Void payment tersebut terlebih dahulu.");
        }

        $activeReturn = \App\Modules\Sales\Models\SalesReturn::where('invoice_id', $invoice->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])
            ->first();
        if ($activeReturn) {
            return back()->with('error', "Invoice tidak bisa di-void: masih ada Retur {$activeReturn->return_number} aktif. Void retur tersebut terlebih dahulu.");
        }

        $activeWarranty = \App\Models\WarrantyOrder::where('invoice_id', $invoice->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])
            ->first();
        if ($activeWarranty) {
            return back()->with('error', "Invoice tidak bisa di-void: masih ada Garansi {$activeWarranty->warranty_number} aktif. Void garansi tersebut terlebih dahulu.");
        }

        $activeBilling = \App\Models\CustomerBillingItem::where('invoice_id', $invoice->id)
            ->whereHas('billing', fn($q) => $q->whereNotIn('status', ['void', 'draft']))
            ->with('billing')
            ->first();
        if ($activeBilling) {
            $bilNum = $activeBilling->billing->billing_number ?? '#' . $activeBilling->billing_id;
            return back()->with('error', "Invoice tidak bisa di-void: masih ada Billing {$bilNum} aktif. Void billing tersebut terlebih dahulu.");
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($invoice) {
                // 1. Reverse stock via delivery. Satu invoice bisa punya BANYAK SJ
                //    (partial + auto-SJ sisa, Bagian A) → balik & void SEMUA yang posted.
                $deliveries = \App\Modules\Sales\Models\SalesDelivery::where('invoice_id', $invoice->id)
                    ->where('status', 'posted')
                    ->get();
                foreach ($deliveries as $delivery) {
                    $this->reverseDeliveryStock($delivery);
                    $delivery->status = 'void';
                    if (\Illuminate\Support\Facades\Schema::hasColumn($delivery->getTable(), 'voided_at')) {
                        $delivery->voided_at = now();
                    }
                    $delivery->save();
                }

                // 2. Mark journal as void
                \App\Core\Journal\Journal::where('reference_type', 'sales_invoice')
                    ->where('reference_id', $invoice->id)
                    ->update(['status' => 'void', 'voided_at' => now()]);

                // 3. Reset advance_applied (uang muka kembali ke saldo SO)
                $invoice->advance_applied = 0;

                // 4. Set status void
                $invoice->status = \App\Enums\InvoiceStatusEnum::VOID;
                $invoice->save();
            });
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal void invoice: ' . $e->getMessage());
        }

        return redirect()->route('sales.invoices.show', $invoice->id)
            ->with('success', 'Invoice berhasil di-void.');
    }

    /**
     * Reverse stok yang dikonsumsi saat delivery posted.
     * Mencatat ledger qty_in untuk mengembalikan saldo stok, dan
     * membuat StockLayer baru sebagai stok kembali (FIFO order tetap terjaga).
     */
    protected function reverseDeliveryStock(\App\Modules\Sales\Models\SalesDelivery $delivery): void
    {
        $delivery->loadMissing('items.product');
        $engine = app(\App\Core\Inventory\InventoryEngine::class);

        foreach ($delivery->items as $item) {
            $product = $item->product;
            if (!$product || in_array($product->sale_type ?? null, ['service', 'non_stock'], true)) {
                continue;
            }
            if ((float) $item->qty <= 0) {
                continue;
            }

            // Cari InventoryCostLayer hasil consume saat delivery posted
            $consumeLayers = \App\Models\InventoryCostLayer::where('product_id', $item->product_id)
                ->where('reference_type', 'sales')
                ->where('reference_id', $delivery->id)
                ->where('qty_out', '>', 0)
                ->get();

            $totalQty = (float) $consumeLayers->sum('qty_out');
            if ($totalQty <= 0) {
                $totalQty = (float) $item->qty;
            }

            // Restore: catat ledger qty_in dan buat StockLayer kembali (avg cost)
            $avgCost = 0;
            if ($consumeLayers->count() > 0 && $totalQty > 0) {
                $totalCost = $consumeLayers->sum(fn($l) => (float) $l->qty_out * (float) $l->unit_cost);
                $avgCost = $totalCost / $totalQty;
            } elseif ((float) $item->qty > 0 && (float) ($item->cogs_total ?? 0) > 0) {
                $avgCost = (float) $item->cogs_total / (float) $item->qty;
            }

            $engine->ledger(
                $item->product_id,
                $delivery->warehouse_id,
                $totalQty,
                0,
                'sales_void',
                $delivery->delivery_number,
                'Void delivery ' . $delivery->delivery_number,
                $delivery->id
            );

            // Catat balik di FIFO sebagai layer baru (qty_remaining penuh)
            \App\Core\Inventory\StockLayer::create([
                'product_id'   => $item->product_id,
                'warehouse_id' => $delivery->warehouse_id,
                'qty_in'       => $totalQty,
                'qty_remaining'=> $totalQty,
                'unit_cost'    => $avgCost,
                'source_type'  => 'sales_void',
                'source_id'    => $delivery->id,
            ]);

            \App\Models\InventoryCostLayer::create([
                'product_id'     => $item->product_id,
                'qty_in'         => $totalQty,
                'qty_balance'    => $totalQty,
                'unit_cost'      => $avgCost,
                'reference_type' => 'sales_void',
                'reference_id'   => $delivery->id,
            ]);
        }
    }

    public function destroy($id)
    {
        $invoice = \App\Models\SalesInvoice::findOrFail($id);

        if ($invoice->status === \App\Enums\InvoiceStatusEnum::POSTED) {
            return back()->with('error', 'Invoice yang sudah posted tidak bisa dihapus. Gunakan Void.');
        }

        $invoice->delete();

        return redirect()->route('sales.invoices.index')
            ->with('success', 'Invoice berhasil dihapus.');
    }

    public function edit($id)
    {
        $invoice = \App\Models\SalesInvoice::with([
            'items.product.bundleComponents.component',
            'salesOrder.customer'
        ])->findOrFail($id);

        $totalAdvance = \App\Modules\Sales\Models\SalesAdvance::where('sales_order_id', $invoice->sales_order_id)
            ->where('status', 'posted')
            ->sum('amount');

        $delivery = \App\Modules\Sales\Models\SalesDelivery::where('sales_order_id', $invoice->sales_order_id)
            ->where('status', 'posted')
            ->first();

        $itemsJson = $invoice->items->map(function ($item) {
            $product = $item->product;
            return [
                'id' => $item->id,
                'sales_order_item_id' => $item->sales_order_item_id,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'qty' => $item->qty,
                'price' => $item->unit_price,
                'discount_type' => $item->discount_type,
                'discount_value' => $item->discount_value,
                'type' => $product->sale_type === 'bundle' ? 'bundle' : 'product',
                'components' => $product->sale_type === 'bundle'
                    ? ($product->bundleItems->isNotEmpty() ? $product->bundleItems->map(function ($c) use ($item) {
                        return [
                            'product_id' => $c->component_product_id,
                            'sku' => $c->componentProduct->sku ?? '',
                            'name' => $c->componentProduct->name ?? '',
                            'qty_required' => $c->qty_required,
                            'qty' => $c->qty_required * $item->qty
                        ];
                    }) : $product->bundleComponents->map(function ($c) use ($item) {
                        return [
                            'product_id' => $c->component_product_id,
                            'sku' => $c->component->sku ?? '',
                            'name' => $c->component->name ?? '',
                            'qty_required' => $c->qty,
                            'qty' => $c->qty * $item->qty
                        ];
                    }))->values()
                    : []
            ];
        });

        $customers  = \App\Models\Customer::all();
        $warehouses = \App\Core\Inventory\Warehouse::all();
        $products   = \App\Core\Inventory\Product::all();

        return view('erp.sales.invoices.edit', compact(
            'invoice',
            'customers',
            'warehouses',
            'products',
            'itemsJson',
            'totalAdvance',
            'delivery'
        ));
    }

    public function update(Request $request, $id, SalesInvoiceService $service)
    {
        $invoice = \App\Models\SalesInvoice::findOrFail($id);

        if ($invoice->status !== 'draft') {
            return back()->with('error', 'Invoice sudah dipost, tidak bisa diedit.');
        }

        $items = [];

        foreach ($request->items as $item) {
            $items[] = new SalesInvoiceItemDTO(
                $item['sales_order_item_id'] ?? null,
                $item['product_id'] ?? null,
                $item['description'] ?? '',
                'product', // or fetch from db/item_type
                (float) ($item['qty'] ?? 0),
                clean_number($item['unit_price'] ?? 0),
                $item['discount_type'] ?? 'nominal',
                clean_number($item['discount_value'] ?? 0),
                clean_number($item['discount_amount'] ?? 0),
                (float) ($item['ppn_percent'] ?? $request->ppn_percent ?? 0),
                (float) ($item['pph_percent'] ?? $request->pph_percent ?? 0),
            );
        }

        $invoiceMethod = in_array($request->delivery_method, ['kurir', 'instant', 'ambil_toko'], true)
            ? $request->delivery_method
            : ($invoice->delivery_method ?? 'kurir');
        $ship = $this->resolveInvoiceShipping($request, $invoiceMethod);

        $dto = new SalesInvoiceDTO(
            $request->sales_order_id ? (int) $request->sales_order_id : null,
            (int) $request->customer_id,
            (int) $request->warehouse_id,
            $request->invoice_date,
            $request->global_discount_type,
            clean_number($request->global_discount_value),
            (float) $request->ppn_percent,
            (float) $request->pph_percent,
            $ship['net'],
            clean_number($request->selling_expense),
            clean_number($request->advance_applied),
            $request->notes,
            $items
        );

        try {
            $service->updateDraft($invoice, $dto);
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Gagal update invoice: ' . $e->getMessage());
        }

        $invoice->update([
            'delivery_method'         => $invoiceMethod,
            'shipping_gross'          => $ship['gross'],
            'shipping_discount_type'  => $ship['disc_type'],
            'shipping_discount_value' => $ship['disc_value'],
            'shipping_courier_code'   => $ship['courier_code'],
            'shipping_service_code'   => $ship['service_code'],
            'package_length'          => $ship['pkg_length'],
            'package_width'           => $ship['pkg_width'],
            'package_height'          => $ship['pkg_height'],
            'shipping_service_name'   => $ship['service_name'],
        ]);

        $action = $request->input('_after_save', '');
        if (in_array($action, ['post', 'print'], true)) {
            $invoice->refresh();
            if ($invoice->status === \App\Enums\InvoiceStatusEnum::DRAFT) {
                try {
                    app(\App\Services\InvoicePostingService::class)->post($invoice);
                } catch (\Exception $e) {
                    return redirect()->route('sales.invoices.index')
                        ->with('error', 'Invoice tersimpan. Gagal post: ' . $e->getMessage());
                }
            }
        }

        if ($action === 'print') {
            return redirect()->route('sales.invoices.print', $invoice->id);
        }

        return redirect()->route('sales.invoices.index')
            ->with('success', $action === 'post' ? 'Invoice berhasil dipost.' : 'Invoice berhasil diupdate.');
    }

    public function updateNotes(Request $request, $id)
    {
        $request->validate(['notes' => 'nullable|string|max:2000']);

        $invoice = \App\Models\SalesInvoice::findOrFail($id);
        $invoice->notes = $request->notes;
        $invoice->save();

        return response()->json(['success' => true]);
    }

    /**
     * Form import Excel masal. Format: 1 baris = 1 invoice, sampai 5 item per baris.
     * Tujuan utama: bikin banyak data buat testing marketplace statement matching.
     */
    public function excelImportForm()
    {
        return view('erp.sales.invoices.excel_import');
    }

    /**
     * Import format Shopee Order export mentah (file `Order.*.xlsx` dari Shopee Seller Center).
     *
     * Cara kerja:
     *   1. Validasi header (kolom 'No. Pesanan', 'Status Pesanan', 'Nomor Referensi SKU', ...).
     *   2. Filter: hanya Status Pesanan = "Selesai".
     *   3. Group rows by No. Pesanan (multi-item per pesanan dirangkum jadi 1 invoice, max 5 item).
     *   4. Per pesanan: bikin SO (status confirmed, customer_po_number = No. Pesanan)
     *      + Invoice (linked ke SO, langsung POSTED).
     *
     * customer_po_number = No. Pesanan â†’ kunci match Marketplace Settlement nanti
     * (lihat MarketplaceSettlementService::matchInvoiceByOrderRef).
     *
     * Match produk: kolom "Nomor Referensi SKU" (varian SKU) â†’ products.sku.
     * Customer: hardcode ke "Shopee" (atau customer dari MarketplaceConfig code=shopee).
     */
    public function excelImport(Request $request, SalesInvoiceService $service)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal baca file: ' . $e->getMessage());
        }

        if (count($rows) < 2) {
            return back()->with('error', 'File kosong / tidak ada data.');
        }

        // -- Validasi header Shopee ----------------------------------------
        $header = $rows[0] ?? [];
        $headerMap = [];
        foreach ($header as $i => $name) {
            $headerMap[trim((string) $name)] = $i;
        }
        $required = ['No. Pesanan', 'Status Pesanan', 'Waktu Pesanan Dibuat',
                     'Nomor Referensi SKU', 'Harga Setelah Diskon', 'Jumlah'];
        $missing = array_diff($required, array_keys($headerMap));
        if (!empty($missing)) {
            return back()->with('error', 'Bukan format Shopee Order. Kolom hilang: ' . implode(', ', $missing));
        }

        $col = fn($name) => $headerMap[$name];
        $colOpt = fn($name) => $headerMap[$name] ?? null;

        $iPesanan  = $col('No. Pesanan');
        $iStatus   = $col('Status Pesanan');
        $iDate     = $col('Waktu Pesanan Dibuat');
        $iSku      = $col('Nomor Referensi SKU');
        $iPrice    = $col('Harga Setelah Diskon');
        $iQty      = $col('Jumlah');
        $iShipping = $colOpt('Ongkos Kirim Dibayar oleh Pembeli');
        $iResi     = $colOpt('No. Resi');
        $iBuyer    = $colOpt('Username (Pembeli)');
        $iProdName = $colOpt('Nama Produk');

        // -- Customer Shopee -----------------------------------------------
        $shopeeCustomer = \App\Models\Customer::whereRaw('LOWER(TRIM(name)) = ?', ['shopee'])->first();
        if (!$shopeeCustomer) {
            return back()->with('error', "Customer 'Shopee' tidak ditemukan di master. Buat dulu di menu Customer.");
        }

        $defaultWarehouse = \App\Core\Inventory\Warehouse::orderBy('id')->first();
        if (!$defaultWarehouse) {
            return back()->with('error', 'Tidak ada warehouse di master.');
        }

        // Build SKU lookup (lowercase trim)
        $productsBySku = \App\Core\Inventory\Product::whereNotNull('sku')
            ->get()->keyBy(fn($p) => strtolower(trim($p->sku)));

        // -- Group baris by No. Pesanan ------------------------------------
        $orders = [];  // [no_pesanan => ['rows' => [...], 'first_row' => N]]
        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $noPesanan = trim((string) ($row[$iPesanan] ?? ''));
            if ($noPesanan === '') continue;

            if (!isset($orders[$noPesanan])) {
                $orders[$noPesanan] = ['rows' => [], 'first_row' => $r + 1];
            }
            $orders[$noPesanan]['rows'][] = $row;
        }

        $postingService = app(\App\Services\InvoicePostingService::class);
        $soService = app(\App\Modules\Sales\Services\SalesOrderService::class);

        $successes = [];
        $errors = [];
        $skipped  = ['not_selesai' => 0, 'duplicate_po' => 0];

        foreach ($orders as $noPesanan => $group) {
            $rowLabel = "Pesanan {$noPesanan} (baris {$group['first_row']})";
            $firstRow = $group['rows'][0];

            try {
                // -- Filter: hanya 'Selesai' -------------------------------
                // Status valid: 'Selesai' atau 'Pesanan diterima, namun pembeli masih dapat
                // mengajukan pengembalian hingga <tanggal>' — keduanya = delivered & dana cair.
                $status = strtolower(trim((string) ($firstRow[$iStatus] ?? '')));
                $isDelivered = $status === 'selesai' || str_starts_with($status, 'pesanan diterima');
                if (!$isDelivered) {
                    $skipped['not_selesai']++;
                    continue;
                }

                // -- Cek duplikat customer_po_number -----------------------
                $dupSoId = \DB::table('sales_orders')
                    ->where('customer_id', $shopeeCustomer->id)
                    ->where('customer_po_number', $noPesanan)
                    ->value('id');
                if ($dupSoId) {
                    $skipped['duplicate_po']++;
                    continue;
                }

                // -- Tanggal: "Waktu Pesanan Dibuat" â†’ Y-m-d ---------------
                $dateRaw = $firstRow[$iDate] ?? '';
                if (is_numeric($dateRaw)) {
                    $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $dateRaw)->format('Y-m-d');
                } else {
                    $ts = strtotime((string) $dateRaw);
                    if ($ts === false) throw new \Exception("Tanggal tidak valid: {$dateRaw}");
                    $date = date('Y-m-d', $ts);
                }

                // -- Build items dari semua baris di group ----------------
                $itemSpecs = [];
                $seenProductIds = [];
                $shippingTotal = 0;
                if ($iShipping !== null) {
                    // Ongkir di-broadcast di tiap row â†’ ambil dari baris pertama saja.
                    $shippingTotal = (float) clean_number($firstRow[$iShipping] ?? 0);
                }

                if (count($group['rows']) > 5) {
                    throw new \Exception("Pesanan punya " . count($group['rows']) . " item, lebih dari max 5 per invoice.");
                }

                foreach ($group['rows'] as $ri => $row) {
                    $sku = trim((string) ($row[$iSku] ?? ''));
                    if ($sku === '') {
                        $prodName = $iProdName !== null ? trim((string) ($row[$iProdName] ?? '')) : '(tanpa nama)';
                        throw new \Exception("Item ke-" . ($ri + 1) . " '{$prodName}': Nomor Referensi SKU kosong.");
                    }

                    $product = $productsBySku[strtolower($sku)] ?? null;
                    if (!$product) {
                        throw new \Exception("SKU '{$sku}' tidak ditemukan di master produk Noud ERP.");
                    }

                    if (in_array($product->id, $seenProductIds, true)) {
                        throw new \Exception("Produk SKU '{$sku}' muncul 2Ã— dalam pesanan ini.");
                    }
                    $seenProductIds[] = $product->id;

                    $qty = (float) clean_number($row[$iQty] ?? 0);
                    $price = (float) clean_number($row[$iPrice] ?? 0);

                    if ($qty <= 0)  throw new \Exception("SKU '{$sku}': qty harus > 0 (got {$qty}).");
                    if ($price < 0) throw new \Exception("SKU '{$sku}': harga tidak boleh negatif.");

                    $itemSpecs[] = [
                        'product' => $product,
                        'qty'     => $qty,
                        'price'   => $price,
                    ];
                }

                if (empty($itemSpecs)) throw new \Exception('Tidak ada item valid.');

                // -- Notes: username pembeli + No. Resi --------------------
                $noteParts = ['Shopee Order'];
                if ($iBuyer !== null) {
                    $buyer = trim((string) ($firstRow[$iBuyer] ?? ''));
                    if ($buyer !== '') $noteParts[] = "Buyer: @{$buyer}";
                }
                if ($iResi !== null) {
                    $resi = trim((string) ($firstRow[$iResi] ?? ''));
                    if ($resi !== '') $noteParts[] = "Resi: {$resi}";
                }
                $notes = implode(' | ', $noteParts);

                // -- 1. Bikin SO draft + items -----------------------------
                // Marketplace customer: order_number = No. Pesanan Shopee.
                $so = \App\Modules\Sales\Models\SalesOrder::create([
                    'order_number'       => \App\Services\NumberGeneratorService::forCustomer('SO', $shopeeCustomer->id, $noPesanan),
                    'customer_id'        => $shopeeCustomer->id,
                    'customer_po_number' => $noPesanan,
                    'warehouse_id'       => $defaultWarehouse->id,
                    'order_date'         => $date,
                    'notes'              => $notes,
                    'status'             => \App\Enums\SalesOrderStatus::DRAFT->value,
                    'grand_total'        => 0,
                ]);

                $subtotal = 0;
                foreach ($itemSpecs as $spec) {
                    $lineSubtotal = $spec['qty'] * $spec['price'];
                    \App\Modules\Sales\Models\SalesOrderItem::create([
                        'sales_order_id'     => $so->id,
                        'product_id'         => $spec['product']->id,
                        'qty'                => $spec['qty'],
                        'unit_price'         => $spec['price'],
                        'discount_type'      => 'nominal',
                        'discount_value'     => 0,
                        'discount_per_unit'  => 0,
                        'net_unit_price'     => $spec['price'],
                        'line_subtotal'      => $lineSubtotal,
                        'line_discount'      => 0,
                        'line_total'         => $lineSubtotal,
                        'conversion_to_base' => 1,
                    ]);
                    $subtotal += $lineSubtotal;
                }
                $grandTotalSO = $subtotal + $shippingTotal;
                $so->update([
                    'subtotal'      => $subtotal,
                    'shipping_cost' => $shippingTotal,
                    'grand_total'   => $grandTotalSO,
                ]);

                // -- 2. Confirm SO -----------------------------------------
                $soService->confirm($so->id);

                // -- 3. Bikin invoice linked ke SO -------------------------
                $items = [];
                foreach ($itemSpecs as $spec) {
                    $items[] = new SalesInvoiceItemDTO(
                        null, $spec['product']->id, $spec['product']->name, 'product',
                        $spec['qty'], $spec['price'], 'nominal', 0, 0, 0, 0,
                    );
                }
                $dto = new SalesInvoiceDTO(
                    $so->id, $shopeeCustomer->id, $defaultWarehouse->id, $date,
                    'nominal', 0, 0, 0, $shippingTotal, 0, 0, $notes, $items,
                );

                $invoice = $service->createDraft($dto);

                // -- 4. Post invoice ---------------------------------------
                try {
                    $postingService->post($invoice);
                } catch (\Throwable $postErr) {
                    $errors[] = "{$rowLabel}: invoice {$invoice->invoice_number} DRAFT (SO {$so->order_number} confirmed) â€” gagal post: " . $postErr->getMessage();
                    continue;
                }

                $successes[] = "{$rowLabel} â†’ {$invoice->invoice_number} (Rp" . number_format($invoice->grand_total, 0, ',', '.') . ")";
            } catch (\Throwable $e) {
                $errors[] = "{$rowLabel}: " . $e->getMessage();
                continue;
            }
        }

        $msg = count($successes) . ' invoice diimport & dipost dari ' . count($orders) . ' pesanan.';
        if ($skipped['not_selesai'] > 0) $msg .= " [{$skipped['not_selesai']} di-skip: status bukan Selesai]";
        if ($skipped['duplicate_po'] > 0) $msg .= " [{$skipped['duplicate_po']} di-skip: No. Pesanan sudah ada]";
        if (!empty($errors)) $msg .= " [{$skipped['not_selesai']} di-skip, " . count($errors) . " gagal]";

        return redirect()->route('sales.invoices.index')
            ->with(count($successes) > 0 ? 'success' : 'error', $msg)
            ->with('import_successes', $successes)
            ->with('import_errors', $errors);
    }

}