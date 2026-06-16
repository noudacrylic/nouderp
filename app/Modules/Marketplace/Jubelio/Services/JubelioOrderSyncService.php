<?php

namespace App\Modules\Marketplace\Jubelio\Services;

use App\Core\Inventory\Product;
use App\DTO\SalesInvoiceDTO;
use App\DTO\SalesInvoiceItemDTO;
use App\DTO\SalesReturnDTO;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Modules\Marketplace\Jubelio\Models\JubelioChannelMap;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Models\JubelioSyncLog;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\CustomerPaymentService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sinkron pesanan Jubelio → dokumen penjualan ERP. Dipanggil oleh cron & webhook
 * (jalur kode tunggal). Idempotent per pesanan via tabel jubelio_order_links.
 *
 * Alur tahap (kumulatif, tiap tahap dijaga flag idempotensi):
 *   dibayar (is_paid)        → buat SO (posting) + Bayar DP
 *   terkirim (is_shipped)    → buat Surat Jalan (post)
 *   selesai (marked/received)→ buat Invoice (post) → MarketplaceEngine settle ke saldo MP
 *   retur                    → buat SalesReturn DRAFT dari SO (tidak di-post; tunggu cek barang)
 */
class JubelioOrderSyncService
{
    public function __construct(
        protected JubelioClient $client,
        protected SalesOrderService $orderService,
        protected CustomerPaymentService $paymentService,
        protected SalesDeliveryService $deliveryService,
        protected SalesInvoiceService $invoiceService,
        protected SalesReturnService $returnService,
    ) {}

    private function setting(): JubelioSetting
    {
        return JubelioSetting::singleton();
    }

    // ───────────────────────────── Entry points ─────────────────────────────

    /** Poll pesanan siap-proses (dibayar) + pesanan selesai, proses tiap pesanan. */
    public function syncOrders(): array
    {
        $stats = ['processed' => 0, 'errors' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        foreach (['ready' => 'listReadyToProcess', 'completed' => 'listCompleted'] as $list) {
            $page = 1;
            do {
                $resp = $this->client->{$list}($page, 50);
                if (!$resp['success']) {
                    break;
                }
                $rows = $this->rows($resp['data']);
                foreach ($rows as $row) {
                    $id = (int) ($row['salesorder_id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    try {
                        $this->syncOrderById($id);
                        $stats['processed']++;
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::error('Jubelio syncOrder error', ['salesorder_id' => $id, 'error' => $e->getMessage()]);
                    }
                }
                $page++;
            } while (count($rows) >= 50 && $page <= 40); // batas aman
        }

        return $stats;
    }

    /**
     * Proses 1 pesanan Jubelio berdasarkan ID: ambil detail lalu jalankan tahap
     * yang sesuai status. Idempotent.
     */
    public function syncOrderById(int $jubelioSoId): ?JubelioOrderLink
    {
        $resp = $this->client->getOrder($jubelioSoId);
        if (!$resp['success']) {
            Log::warning('Jubelio getOrder gagal', ['id' => $jubelioSoId, 'error' => $resp['error']]);
            return null;
        }
        $detail = $resp['data'];

        $link = JubelioOrderLink::firstOrNew(['jubelio_salesorder_id' => $jubelioSoId]);
        $link->jubelio_salesorder_no = $detail['salesorder_no'] ?? $link->jubelio_salesorder_no;
        $link->store = $this->storeName($detail) ?: $link->store;

        // Pesanan dibatalkan → catat & berhenti (tidak auto-void SO; tangani manual).
        if (!empty($detail['is_canceled'])) {
            $link->last_status = 'canceled';
            $link->save();
            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::SKIP, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $jubelioSoId), [
                'reference'             => $link->jubelio_salesorder_no,
                'jubelio_salesorder_id' => $jubelioSoId,
                'message'               => 'Pesanan dibatalkan di Jubelio — tangani manual bila SO sudah dibuat.',
            ]);
            return $link;
        }

        // TAHAP A — dibayar → SO + DP.
        // (Aman dari duplikasi via unique constraint jubelio_salesorder_id + transaksi
        // internal; API resolveItems sengaja di luar lock.)
        if ($this->isPaid($detail) && !$link->dp_posted) {
            $this->ensureSalesOrderAndDp($detail, $link);
        }

        // TAHAP B & C — SJ & Invoice. Keduanya murni DB → bungkus dalam satu transaksi
        // dgn lockForUpdate pada baris link + re-cek flag, supaya webhook & cron tidak
        // memproses tahap yang sama bersamaan (SJ/Invoice dobel → stok keluar dobel).
        $needB = $link->sales_order_id && $this->isShipped($detail)   && !$link->sj_created;
        $needC = $link->sales_order_id && $this->isCompleted($detail) && !$link->invoice_posted;
        if ($needB || $needC) {
            DB::transaction(function () use ($link, $detail) {
                $locked = JubelioOrderLink::where('id', $link->id)->lockForUpdate()->first();
                if (!$locked) {
                    return;
                }

                if ($this->isShipped($detail) && !$locked->sj_created) {
                    $this->ensureDelivery($locked);
                }
                if ($locked->sales_order_id && $this->isCompleted($detail) && !$locked->invoice_posted) {
                    $this->ensureInvoice($detail, $locked);
                }

                // Sinkronkan flag hasil ke instance luar agar save() metadata di bawah
                // tidak me-revert flag. (Re-run idempotent: ensureInvoice cek exists,
                // ensureDelivery via alreadyDelivered, jadi clobber pun aman.)
                $link->sj_created         = $locked->sj_created;
                $link->invoice_posted     = $locked->invoice_posted;
                $link->jubelio_invoice_id = $locked->jubelio_invoice_id;
            });
        }

        $link->last_status = $this->statusLabel($detail);
        $link->save();

        return $link;
    }

    /** Poll retur belum diproses → buat draft SalesReturn dari SO. */
    public function syncReturns(): array
    {
        $stats = ['created' => 0, 'skipped' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        $resp = $this->client->listUnprocessedReturns(1, 100);
        if (!$resp['success']) {
            return $stats;
        }

        // Kelompokkan baris retur per pesanan Jubelio (salesorder_id).
        $byOrder = [];
        foreach ($this->rows($resp['data']) as $row) {
            $soId = (int) ($row['salesorder_id'] ?? 0);
            if ($soId > 0) {
                $byOrder[$soId][] = $row;
            }
        }

        foreach ($byOrder as $soId => $rows) {
            $link = JubelioOrderLink::where('jubelio_salesorder_id', $soId)->first();
            // Retur dibuat dari SO; butuh SO ERP yang sudah terbentuk & belum punya draft retur.
            if (!$link || !$link->sales_order_id || $link->return_created) {
                $stats['skipped']++;
                continue;
            }

            // Klaim atomik return_created agar webhook & cron tak membuat draft retur
            // dobel (createReturnDraft punya API call, jadi tak dibungkus lock penuh).
            $claimed = JubelioOrderLink::where('id', $link->id)
                ->where('return_created', false)
                ->update(['return_created' => true]);
            if (!$claimed) {
                $stats['skipped']++;
                continue;
            }

            try {
                $created = $this->createReturnDraft($link, $rows);
                if ($created) {
                    $stats['created']++;
                    JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Retur pesanan ' . ($link->jubelio_salesorder_no ?: $soId), [
                        'reference'             => $link->jubelio_salesorder_no,
                        'jubelio_salesorder_id' => $soId,
                        'message'               => 'Draft Retur Penjualan dibuat (menunggu cek barang).',
                    ]);
                } else {
                    // Tak ada draft dibuat (SO hilang / item tak terpetakan) → lepas klaim
                    // agar bisa dicoba lagi nanti.
                    JubelioOrderLink::where('id', $link->id)->update(['return_created' => false]);
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                JubelioOrderLink::where('id', $link->id)->update(['return_created' => false]);
                $stats['skipped']++;
                Log::error('Jubelio createReturnDraft error', ['salesorder_id' => $soId, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    // ───────────────────────────── Tahap A: SO + DP ─────────────────────────────

    private function ensureSalesOrderAndDp(array $detail, JubelioOrderLink $link): void
    {
        $setting = $this->setting();

        $store      = $this->storeName($detail);
        $customerId = JubelioChannelMap::resolveCustomerId($store) ?: $setting->default_customer_id;
        $warehouseId = $setting->default_warehouse_id;

        if (!$customerId || !$warehouseId) {
            $this->fail($link, 'Customer/gudang default Jubelio belum diatur (Settings → Jubelio).');
            return;
        }

        // Resolusi semua item dulu — jangan buat SO sebagian bila ada item tak dikenal.
        $resolved = $this->resolveItems($detail['items'] ?? []);
        if ($resolved === null) {
            $this->fail($link, 'Sebagian item pesanan tidak cocok dengan produk ERP (SKU belum sinkron).');
            return;
        }
        if (empty($resolved)) {
            $this->fail($link, 'Pesanan tanpa item yang dapat diproses.');
            return;
        }

        DB::transaction(function () use ($detail, $link, $customerId, $warehouseId, $resolved, $store) {
            $poNumber = $detail['salesorder_no'] ?? ('JBL-' . $link->jubelio_salesorder_id);

            $subtotal = 0.0;
            foreach ($resolved as $r) {
                $subtotal += $r['line_total'];
            }
            $shipping   = (float) ($detail['shipping_cost'] ?? 0);
            $grandTotal = (float) ($detail['grand_total'] ?? ($subtotal + $shipping));

            // Rekonsiliasi: selisih dilipat ke diskon global (jika +) atau biaya tambahan (jika -)
            // agar grand_total SO == grand_total Jubelio (DP menutup penuh).
            $diff = round($subtotal + $shipping - $grandTotal, 2);
            $globalDiscount = $diff > 0 ? $diff : 0.0;
            $expense        = $diff < 0 ? -$diff : 0.0;

            $so = SalesOrder::create([
                'order_number'          => NumberGeneratorService::forCustomer('SO', $customerId, $poNumber),
                'customer_id'           => $customerId,
                'customer_po_number'    => $poNumber,
                'warehouse_id'          => $warehouseId,
                'delivery_method'       => 'kurir',
                'order_date'            => $this->orderDate($detail),
                'notes'                 => 'Pesanan Jubelio' . ($store ? " ({$store})" : '') . ' — ' . $poNumber,
                'status'                => SalesOrderStatus::DRAFT->value,
                'subtotal'              => $subtotal,
                'discount_total'        => $globalDiscount,
                'global_discount_type'  => 'nominal',
                'global_discount_value' => $globalDiscount,
                'global_discount_amount'=> $globalDiscount,
                'shipping_cost'         => $shipping,
                'additional_fee'        => $expense,
                'grand_total'           => $grandTotal,
            ]);

            foreach ($resolved as $r) {
                SalesOrderItem::create([
                    'sales_order_id'     => $so->id,
                    'product_id'         => $r['product']->id,
                    'description'        => $r['description'],
                    'unit_name'          => $r['product']->base_unit,
                    'conversion_to_base' => 1,
                    'qty'                => $r['qty'],
                    'unit_price'         => $r['unit_price'],
                    'discount_type'      => 'nominal',
                    'discount_value'     => 0,
                    'discount_per_unit'  => 0,
                    'net_unit_price'     => $r['unit_price'],
                    'line_subtotal'      => $r['line_total'],
                    'line_discount'      => 0,
                    'line_total'         => $r['line_total'],
                ]);
            }

            // Posting SO (reservasi stok + auto-produksi preorder mengikuti pola existing).
            $this->orderService->confirm($so->id);

            $link->sales_order_id = $so->id;
            $link->store = $store ?: $link->store;

            // Bayar DP = grand_total. Untuk marketplace, kas = akun Titipan/Hold marketplace
            // sehingga settlement (Hold→Wallet) saat invoice menutup dengan rapi.
            $this->postAdvance($so, $customerId, $link);

            // Persist progres tahap A di dalam transaksi agar konsisten dengan SO yang dibuat.
            $link->last_error = null;
            $link->save();

            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
                'reference'             => $link->jubelio_salesorder_no,
                'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'               => 'Sales Order ' . $so->order_number . ' dibuat + DP diposting' . ($store ? " ({$store})" : ''),
                'meta'                  => ['sales_order_id' => $so->id, 'grand_total' => (float) $so->grand_total],
            ]);
        });
    }

    /**
     * Posting uang muka (DP) sebesar grand_total ke akun hold marketplace.
     * Bila customer bukan marketplace / tak ada akun hold, DP dilewati (alur AR biasa).
     */
    private function postAdvance(SalesOrder $so, int $customerId, JubelioOrderLink $link): void
    {
        $config = MarketplaceConfig::where('customer_id', $customerId)->where('is_active', true)->first();
        $cashAccountId = $config?->account_receivable_hold_id;

        if (!$cashAccountId) {
            Log::info('Jubelio DP dilewati (tanpa akun hold marketplace)', ['so' => $so->id, 'customer' => $customerId]);
            $link->dp_posted = true; // tandai agar tidak dicoba ulang; invoice nanti pakai AR biasa
            return;
        }

        $payment = $this->paymentService->create([
            'customer_id'     => $customerId,
            'date'            => $so->order_date,
            'cash_account_id' => $cashAccountId,
            'amount'          => (float) $so->grand_total,
            'payment_type'    => 'advance',
            'sales_order_id'  => $so->id,
            'notes'           => 'DP Jubelio ' . $so->customer_po_number,
        ]);

        $this->paymentService->post($payment->id, null, [], [$so->id], false);

        $link->dp_posted = true;
        $link->customer_payment_id = $payment->id;
    }

    // ───────────────────────────── Tahap B: Surat Jalan ─────────────────────────────

    private function ensureDelivery(JubelioOrderLink $link): void
    {
        $so = SalesOrder::find($link->sales_order_id);
        if (!$so) {
            return;
        }
        $delivery = $this->deliveryService->createFromOrder($so, 'kurir');
        if ($delivery) {
            $this->deliveryService->post($delivery->id);
        }
        $link->sj_created = true;
        $link->save();
        JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
            'reference'             => $link->jubelio_salesorder_no,
            'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
            'message'               => 'Surat Jalan dibuat untuk SO ' . $so->order_number . ' (stok keluar).',
        ]);
    }

    // ───────────────────────────── Tahap C: Invoice ─────────────────────────────

    private function ensureInvoice(array $detail, JubelioOrderLink $link): void
    {
        $so = SalesOrder::with('items')->find($link->sales_order_id);
        if (!$so) {
            return;
        }

        // Idempotensi tambahan: bila SO sudah punya invoice, cukup tandai.
        if (\App\Models\SalesInvoice::where('sales_order_id', $so->id)->exists()) {
            $link->invoice_posted = true;
            $link->save();
            return;
        }

        $shipping   = (float) ($detail['shipping_cost'] ?? $so->shipping_cost ?? 0);
        $grandTotal = (float) ($detail['grand_total'] ?? $so->grand_total);

        $items = [];
        $subtotal = 0.0;
        foreach ($so->items as $soItem) {
            $remaining = (float) $soItem->qty - (float) $soItem->qty_invoiced;
            if ($remaining <= 0) {
                continue;
            }
            $unit = (float) $soItem->net_unit_price ?: (float) $soItem->unit_price;
            $subtotal += $unit * $remaining;
            $items[] = new SalesInvoiceItemDTO(
                sales_order_item_id: $soItem->id,
                product_id: $soItem->product_id,
                description: (string) ($soItem->description ?? ''),
                item_type: 'product',
                qty: $remaining,
                unit_price: $unit,
                discount_type: 'nominal',
                discount_value: 0,
                discount_amount: 0,
                ppn_percent: 0,
                pph_percent: 0,
            );
        }

        if (empty($items)) {
            $link->invoice_posted = true;
            $link->save();
            return;
        }

        // Rekonsiliasi grand_total (sama seperti SO) agar advance menutup penuh.
        $diff = round($subtotal + $shipping - $grandTotal, 2);
        $globalDiscount = $diff > 0 ? $diff : 0.0;
        $additionalFee  = $diff < 0 ? -$diff : 0.0;

        $dto = new SalesInvoiceDTO(
            sales_order_id: $so->id,
            customer_id: $so->customer_id,
            warehouse_id: $so->warehouse_id,
            invoice_date: now()->toDateString(),
            global_discount_type: 'nominal',
            global_discount_value: $globalDiscount,
            ppn_percent: 0,
            pph_percent: 0,
            shipping_cost: $shipping,
            additional_fee: $additionalFee,
            advance_applied: 0, // dihitung otomatis oleh createDraft dari SalesAdvance
            notes: 'Invoice otomatis Jubelio ' . $so->customer_po_number,
            items: $items,
        );

        $invoice = $this->invoiceService->createDraft($dto);
        app(\App\Services\InvoicePostingService::class)->post($invoice);

        $link->invoice_posted = true;
        $link->jubelio_invoice_id = $detail['invoice_id'] ?? null;
        $link->save();
        JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
            'reference'             => $link->jubelio_salesorder_no,
            'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
            'message'               => 'Invoice ' . ($invoice->invoice_number ?? '') . ' dibuat & diposting untuk SO ' . $so->order_number . '.',
            'meta'                  => ['invoice_id' => $invoice->id ?? null],
        ]);
    }

    // ───────────────────────────── Retur draft ─────────────────────────────

    /** @return bool true bila draft retur benar-benar dibuat. */
    private function createReturnDraft(JubelioOrderLink $link, array $rows): bool
    {
        $so = SalesOrder::with('items')->find($link->sales_order_id);
        if (!$so) {
            return false;
        }

        // Map tiap baris retur Jubelio (item_id, qty) ke SO item ERP.
        $items = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            $qty    = (float) ($row['qty'] ?? $row['qty_in_base'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            $product = $this->resolveProduct($itemId);
            if (!$product) {
                continue;
            }
            $soItem = $so->items->firstWhere('product_id', $product->id);
            if (!$soItem) {
                continue;
            }
            $items[] = [
                'invoice_item_id' => $soItem->id, // getDoc(SO) mencari by SO item id
                'qty'             => min($qty, (float) $soItem->qty),
                'condition'       => 'good', // default; dikoreksi manual saat cek barang
            ];
        }

        if (empty($items)) {
            return false;
        }

        $dto = new SalesReturnDTO(
            customer_id: $so->customer_id,
            items: $items,
            date: now()->toDateString(),
            sales_order_id: $so->id,
        );

        $this->returnService->saveDraft($dto); // DRAFT — tidak di-post
        // Flag return_created di-set oleh pemanggil (klaim atomik) — lihat syncReturns.
        return true;
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    /**
     * Resolusi item pesanan → produk ERP. Return null bila ADA item tak dikenal
     * (supaya SO tidak dibuat sebagian); array kosong bila semua item non-fisik.
     * @return array<int,array{product:Product,qty:float,unit_price:float,line_total:float,description:string}>|null
     */
    private function resolveItems(array $jubelioItems): ?array
    {
        $out = [];
        foreach ($jubelioItems as $it) {
            $itemId = (int) ($it['item_id'] ?? 0);
            $qty    = (float) ($it['qty'] ?? $it['qty_in_base'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            $product = $this->resolveProduct($itemId);
            if (!$product) {
                return null; // ada item tak dikenal → batalkan
            }
            $amount = (float) ($it['amount'] ?? ((float) ($it['price'] ?? 0) * $qty));
            $netUnit = $qty > 0 ? round($amount / $qty, 2) : 0.0;
            // Nama line item: deskripsi marketplace lebih diutamakan (sering custom).
            $desc = trim((string) ($it['description'] ?? '')) ?: $product->name;

            $out[] = [
                'product'     => $product,
                'qty'         => $qty,
                'unit_price'  => $netUnit,
                'line_total'  => round($netUnit * $qty, 2),
                'description' => $desc,
            ];
        }
        return $out;
    }

    /** item_id Jubelio → Product ERP (cache di products.jubelio_item_id; fallback via SKU). */
    private function resolveProduct(int $itemId): ?Product
    {
        $product = Product::where('jubelio_item_id', $itemId)->first();
        if ($product) {
            return $product;
        }

        $resp = $this->client->getItem($itemId);
        if (!$resp['success']) {
            return null;
        }
        $data = $resp['data'];
        $sku = $data['item_code'] ?? $data['sku'] ?? ($data['items'][0]['item_code'] ?? null);
        if (!$sku) {
            return null;
        }
        $product = Product::where('sku', $sku)->first();
        if ($product) {
            $product->forceFill(['jubelio_item_id' => $itemId])->save();
        }
        return $product;
    }

    private function rows($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        // beberapa endpoint membungkus di 'list' / 'items'
        foreach (['list', 'items', 'orders'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                return $data[$k];
            }
        }
        return array_is_list($data) ? $data : [];
    }

    private function storeName(array $detail): ?string
    {
        $s = $detail['store_name'] ?? $detail['store'] ?? $detail['source_name'] ?? null;
        return $s ? trim((string) $s) : null;
    }

    private function isPaid(array $d): bool
    {
        return !empty($d['is_paid']) || !empty($d['payment_date']);
    }

    private function isShipped(array $d): bool
    {
        return !empty($d['is_shipped']) || !empty($d['tracking_no']) || !empty($d['shipped_date']);
    }

    private function isCompleted(array $d): bool
    {
        return !empty($d['marked_as_complete']) || !empty($d['received_date'])
            || strtoupper((string) ($d['wms_status'] ?? '')) === 'COMPLETED';
    }

    private function statusLabel(array $d): string
    {
        if ($this->isCompleted($d)) return 'completed';
        if ($this->isShipped($d))   return 'shipped';
        if ($this->isPaid($d))      return 'paid';
        return 'pending';
    }

    private function orderDate(array $d): string
    {
        $raw = $d['transaction_date'] ?? $d['created_date'] ?? null;
        try {
            return $raw ? \Carbon\Carbon::parse($raw)->toDateString() : now()->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }

    private function fail(JubelioOrderLink $link, string $msg): void
    {
        $link->last_error = $msg;
        $link->save();
        JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::FAIL, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
            'reference'             => $link->jubelio_salesorder_no,
            'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
            'message'               => $msg,
        ]);
        Log::warning('Jubelio order belum dapat diproses', ['id' => $link->jubelio_salesorder_id, 'reason' => $msg]);
    }
}
