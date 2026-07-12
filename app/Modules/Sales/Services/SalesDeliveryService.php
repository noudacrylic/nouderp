<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\SalesDelivery;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\ProductBundle;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;
use Exception;

class SalesDeliveryService
{
    protected InventoryEngine $inventory;

    public function __construct(InventoryEngine $inventory)
    {
        $this->inventory = $inventory;
    }

    /**
     * Buat SJ otomatis dari invoice, HANYA untuk SISA yang belum dikirim.
     * $alreadyDelivered: [product_id => qty base yang sudah dikirim via SJ lain].
     * Return null jika tidak ada sisa (semua sudah dikirim partial).
     */
    public function createFromInvoice($invoice, array $alreadyDelivered = [])
    {
        // 1. Bangun kebutuhan per produk (expand bundle ke komponen)
        $needed = []; // product_id => ['qty' => float, 'soItemId' => ?int]
        foreach ($invoice->items as $item) {
            $product = $item->product;

            // Jasa & non_stock tidak dikirim — dijurnal langsung dari InvoicePostingService.
            if ($product && in_array($product->sale_type, ['service', 'non_stock'], true)) {
                continue;
            }

            if ($product && $product->sale_type === 'bundle') {
                $components = BundleComponent::where('bundle_product_id', $item->product_id)->get();
                $qtyField = 'qty';
                if ($components->isEmpty()) {
                    $components = ProductBundle::where('bundle_product_id', $item->product_id)->get();
                    $qtyField = 'qty_required';
                }
                foreach ($components as $comp) {
                    $this->addNeeded($needed, $comp->component_product_id, ($comp->{$qtyField} ?? 1) * $item->qty, $item->sales_order_item_id ?? null);
                }
            } else {
                $this->addNeeded($needed, $item->product_id, (float) $item->qty, $item->sales_order_item_id ?? null);
            }
        }

        // 2. Kurangi yang sudah dikirim → sisa
        $toCreate = [];
        foreach ($needed as $pid => $n) {
            $remaining = $n['qty'] - (float) ($alreadyDelivered[$pid] ?? 0);
            if ($remaining > 0.00001) {
                $toCreate[$pid] = ['qty' => $remaining, 'soItemId' => $n['soItemId']];
            }
        }

        if (empty($toCreate)) {
            return null; // semua sudah terkirim via SJ partial
        }

        // 3. Buat SJ + items (retry untuk nomor unik DOINV)
        for ($i = 0; $i < 5; $i++) {
            try {
                // Salin info kurir dari faktur agar SJ mandiri (untuk cetak label / generate resi).
                $method = $invoice->delivery_method ?: 'kurir';
                $courierName = $method === 'ambil_toko'
                    ? 'Ambil di Toko'
                    : (trim(strtoupper((string) ($invoice->shipping_courier_code ?? '')) . ' ' . (string) ($invoice->shipping_service_name ?? '')) ?: null);

                $delivery = SalesDelivery::create([
                    'delivery_number'       => NumberGeneratorService::generate('DOINV'),
                    'invoice_id'            => $invoice->id,
                    'sales_order_id'        => $invoice->sales_order_id ?? null,
                    'reference_type'        => 'sales_invoice',
                    'reference_id'          => $invoice->id,
                    'warehouse_id'          => $invoice->warehouse_id,
                    'delivery_date'         => $invoice->invoice_date,
                    'delivery_method'       => $method,
                    'courier_name'          => $courierName,
                    'shipping_courier_code' => $method === 'ambil_toko' ? null : ($invoice->shipping_courier_code ?: null),
                    'shipping_service_code' => $method === 'ambil_toko' ? null : ($invoice->shipping_service_code ?: null),
                    'status'                => 'draft',
                ]);

                foreach ($toCreate as $pid => $row) {
                    $delivery->items()->create([
                        'product_id'          => $pid,
                        'qty'                 => $row['qty'],
                        'sales_order_item_id' => $row['soItemId'],
                    ]);
                }

                return $delivery;

            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->errorInfo[1] == 1062) {
                    continue; // nomor bentrok, coba berikutnya
                }
                throw $e;
            }
        }

        throw new Exception('Gagal membuat nomor Surat Jalan otomatis setelah beberapa kali percobaan.');
    }

    /**
     * Buat SJ dari Sales Order untuk SISA yang belum dikirim (expand bundle).
     * Dipakai alur "Ambil di Toko": handover penuh saat customer datang.
     * Return null jika tidak ada sisa.
     */
    public function createFromOrder(\App\Modules\Sales\Models\SalesOrder $so, string $deliveryMethod = 'ambil_toko')
    {
        $so->loadMissing('items.product');

        // 1. Kebutuhan per produk dari item SO (expand bundle ke komponen).
        $needed = [];
        foreach ($so->items as $item) {
            $product = $item->product;
            if ($product && in_array($product->sale_type, ['service', 'non_stock'], true)) {
                continue;
            }
            if ($product && $product->sale_type === 'bundle') {
                $components = BundleComponent::where('bundle_product_id', $item->product_id)->get();
                $qtyField = 'qty';
                if ($components->isEmpty()) {
                    $components = ProductBundle::where('bundle_product_id', $item->product_id)->get();
                    $qtyField = 'qty_required';
                }
                foreach ($components as $comp) {
                    $this->addNeeded($needed, $comp->component_product_id, ($comp->{$qtyField} ?? 1) * $item->qty, $item->id);
                }
            } else {
                $this->addNeeded($needed, $item->product_id, (float) $item->qty, $item->id);
            }
        }

        // 2. Kurangi yang sudah dikirim via SJ lain (non-void) → sisa.
        $alreadyDelivered = [];
        $existing = SalesDelivery::with('items')
            ->where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->get();
        foreach ($existing as $d) {
            foreach ($d->items as $it) {
                $alreadyDelivered[$it->product_id] = ($alreadyDelivered[$it->product_id] ?? 0) + (float) $it->qty;
            }
        }

        $toCreate = [];
        foreach ($needed as $pid => $n) {
            $remaining = $n['qty'] - (float) ($alreadyDelivered[$pid] ?? 0);
            if ($remaining > 0.00001) {
                $toCreate[$pid] = ['qty' => $remaining, 'soItemId' => $n['soItemId']];
            }
        }

        if (empty($toCreate)) {
            return null; // semua sudah terkirim
        }

        // 3. Buat SJ + items (retry nomor unik).
        for ($i = 0; $i < 5; $i++) {
            try {
                $delivery = SalesDelivery::create([
                    'delivery_number' => NumberGeneratorService::generate('DOSO'),
                    'sales_order_id'  => $so->id,
                    'reference_type'  => 'sales_order',
                    'reference_id'    => $so->id,
                    'warehouse_id'    => $so->warehouse_id,
                    'delivery_date'   => now()->toDateString(),
                    'delivery_method' => $deliveryMethod,
                    'courier_name'    => $deliveryMethod === 'ambil_toko' ? 'Ambil di Toko' : null,
                    'status'          => 'draft',
                ]);

                foreach ($toCreate as $pid => $row) {
                    $delivery->items()->create([
                        'product_id'          => $pid,
                        'qty'                 => $row['qty'],
                        'sales_order_item_id' => $row['soItemId'],
                    ]);
                }

                return $delivery;
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->errorInfo[1] == 1062) {
                    continue;
                }
                throw $e;
            }
        }

        throw new Exception('Gagal membuat nomor Surat Jalan otomatis setelah beberapa kali percobaan.');
    }

    private function addNeeded(array &$needed, $productId, $qty, $soItemId): void
    {
        if (!isset($needed[$productId])) {
            $needed[$productId] = ['qty' => 0, 'soItemId' => $soItemId];
        }
        $needed[$productId]['qty'] += (float) $qty;
    }

    /**
     * Void Surat Jalan posted: balikkan stok (FIFO per-cost, hormati cogs_deferred) +
     * balik jurnal booking ongkir. Sumber kebenaran void SJ — dipanggil oleh
     * SalesDeliveryController::void (manual) DAN auto-void pembatalan Jubelio.
     * Lempar RuntimeException untuk pelanggaran dependency (dipetakan ke pesan UI di caller).
     */
    public function voidDelivery(SalesDelivery $delivery): void
    {
        $delivery->loadMissing(['items.product', 'invoice']);

        if ($delivery->status !== 'posted') {
            throw new \RuntimeException('Hanya Surat Jalan posted yang bisa di-void.');
        }

        // ── Dependency checks ──
        // DO yang dibuat DARI faktur (DOINV, reference_type='sales_invoice') boleh di-void
        // lebih dulu: faktur adalah induk, jadi tak perlu menunggu faktur. Void SJ hanya
        // membalik stok/HPP; pendapatan faktur tetap sampai faktur sendiri di-void. Guard
        // faktur-aktif hanya berlaku untuk SJ yang menjadi INDUK faktur (mencegah deadlock).
        $isFromInvoice = ($delivery->reference_type ?? null) === 'sales_invoice';
        if (!$isFromInvoice && $delivery->invoice && !in_array(
                ($delivery->invoice->status instanceof \App\Enums\InvoiceStatusEnum
                    ? $delivery->invoice->status->value
                    : $delivery->invoice->status),
                ['void', 'cancelled', 'draft'],
                true
            )) {
            throw new \RuntimeException("Surat Jalan tidak bisa di-void: masih terkait Invoice {$delivery->invoice->invoice_number} aktif. Void invoice tersebut terlebih dahulu.");
        }

        // Cek warranty delivery (warranty_order references via reference_type)
        if (($delivery->reference_type ?? null) === 'warranty_order' && $delivery->reference_id) {
            $war = \App\Models\WarrantyOrder::find($delivery->reference_id);
            if ($war && !in_array($war->status, ['void', 'cancelled', 'draft'], true)) {
                throw new \RuntimeException("Surat Jalan tidak bisa di-void: masih terkait Garansi {$war->warranty_number} aktif. Void garansi tersebut terlebih dahulu.");
            }
        }

        DB::transaction(function () use ($delivery) {
            // Reverse stock per item
            $engine = app(\App\Core\Inventory\InventoryEngine::class);

            foreach ($delivery->items as $item) {
                $product = $item->product;
                if (!$product || in_array($product->sale_type ?? null, ['service', 'non_stock'], true)) {
                    continue;
                }
                if ((float) $item->qty <= 0) continue;

                // Item yang masih di-defer: dikirim dengan stok minus, COGS belum dihitung
                // dan TIDAK ada layer FIFO yang dikonsumsi. Cukup balikkan ledger (tambah qty),
                // tanpa membuat layer biaya phantom.
                if ($item->cogs_deferred) {
                    $engine->ledger(
                        $item->product_id,
                        $delivery->warehouse_id,
                        (float) $item->qty,
                        0,
                        'sales_void',
                        $delivery->delivery_number,
                        'Void delivery (deferred) ' . $delivery->delivery_number,
                        $delivery->id,
                        true
                    );
                    continue;
                }

                // Baris konsumsi FIFO milik SJ ini (per-layer, simpan unit_cost asli).
                $consumeLayers = \App\Models\InventoryCostLayer::where('product_id', $item->product_id)
                    ->where('reference_type', 'sales')
                    ->where('reference_id', $delivery->id)
                    ->where('qty_out', '>', 0)
                    ->orderBy('id')
                    ->get();

                $totalQty = (float) $consumeLayers->sum('qty_out');

                if ($totalQty <= 0) {
                    // Tak ada jejak konsumsi (data lama): fallback kembalikan qty item ke
                    // ledger saja dgn cost dari cogs_total bila ada.
                    $totalQty = (float) $item->qty;
                    $fbCost = ((float) $item->qty > 0 && (float) ($item->cogs_total ?? 0) > 0)
                        ? (float) $item->cogs_total / (float) $item->qty : 0;

                    $engine->ledger(
                        $item->product_id, $delivery->warehouse_id, $totalQty, 0,
                        'sales_void', $delivery->delivery_number,
                        'Void delivery ' . $delivery->delivery_number, $delivery->id
                    );
                    \App\Core\Inventory\StockLayer::create([
                        'product_id'    => $item->product_id,
                        'warehouse_id'  => $delivery->warehouse_id,
                        'qty_in'        => $totalQty,
                        'qty_remaining' => $totalQty,
                        'unit_cost'     => $fbCost,
                        'source_type'   => 'sales_void',
                        'source_id'     => $delivery->id,
                    ]);
                    \App\Models\InventoryCostLayer::create([
                        'product_id'     => $item->product_id,
                        'qty_in'         => $totalQty,
                        'qty_balance'    => $totalQty,
                        'unit_cost'      => $fbCost,
                        'reference_type' => 'sales_void',
                        'reference_id'   => $delivery->id,
                    ]);
                    continue;
                }

                // 1) Ledger: kembalikan qty fisik ke running-balance.
                $engine->ledger(
                    $item->product_id, $delivery->warehouse_id, $totalQty, 0,
                    'sales_void', $delivery->delivery_number,
                    'Void delivery ' . $delivery->delivery_number, $delivery->id
                );

                // 2) Kembalikan qty_remaining ke LAYER ASLI per unit_cost (jaga FIFO &
                //    cost asli) — bukan bikin layer rata-rata baru di akhir antrean.
                $byCost = [];
                foreach ($consumeLayers as $cl) {
                    $key = (string) round((float) $cl->unit_cost, 4);
                    $byCost[$key] = ($byCost[$key] ?? 0) + (float) $cl->qty_out;
                }

                foreach ($byCost as $costKey => $restoreQty) {
                    $cost = (float) $costKey;
                    $remainingToRestore = $restoreQty;

                    // Layer asli ber-cost sama yg sempat terkuras (FIFO: tertua dulu).
                    $stockLayers = \App\Core\Inventory\StockLayer::where('product_id', $item->product_id)
                        ->where('warehouse_id', $delivery->warehouse_id)
                        ->whereRaw('ROUND(unit_cost, 4) = ?', [round($cost, 4)])
                        ->whereColumn('qty_remaining', '<', 'qty_in')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    foreach ($stockLayers as $sl) {
                        if ($remainingToRestore <= 0.00001) break;
                        $headroom = (float) $sl->qty_in - (float) $sl->qty_remaining;
                        $add = min($headroom, $remainingToRestore);
                        $sl->qty_remaining = (float) $sl->qty_remaining + $add;
                        $sl->save();
                        $remainingToRestore -= $add;
                    }

                    // Sisa tak terpetakan (cost bergeser/data lama) → layer pemulih baru.
                    if ($remainingToRestore > 0.00001) {
                        \App\Core\Inventory\StockLayer::create([
                            'product_id'    => $item->product_id,
                            'warehouse_id'  => $delivery->warehouse_id,
                            'qty_in'        => $remainingToRestore,
                            'qty_remaining' => $remainingToRestore,
                            'unit_cost'     => $cost,
                            'source_type'   => 'sales_void',
                            'source_id'     => $delivery->id,
                        ]);
                    }

                    // Audit ledger: entri pembalik per-cost.
                    \App\Models\InventoryCostLayer::create([
                        'product_id'     => $item->product_id,
                        'qty_in'         => $restoreQty,
                        'qty_balance'    => $restoreQty,
                        'unit_cost'      => $cost,
                        'reference_type' => 'sales_void',
                        'reference_id'   => $delivery->id,
                    ]);
                }
            }

            // Fase 5 — balik jurnal booking ongkir (Saldo Biteship kembali) bila ada resi.
            if ($delivery->isBooked()) {
                app(\App\Modules\Shipping\Services\ShippingAccountingService::class)->reverseBooking($delivery);
                $delivery->shipping_status = 'void';
                $delivery->provider_order_id = null;
            }

            $delivery->status = 'void';
            if (\Illuminate\Support\Facades\Schema::hasColumn($delivery->getTable(), 'voided_at')) {
                $delivery->voided_at = now();
            }
            $delivery->save();
        });
    }

    public function post(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId) {

            $delivery = SalesDelivery::with(['items.product', 'order'])
                ->lockForUpdate()
                ->findOrFail($deliveryId);

            if ($delivery->status === 'posted') {
                throw new Exception("Delivery already posted");
            }

            if ($delivery->items->isEmpty()) {
                throw new Exception("Delivery has no items");
            }

            foreach ($delivery->items as $item) {

                $product = $item->product;

                if (!$product) {
                    throw new Exception("Product not found");
                }

                if ($item->qty <= 0) {
                    continue;
                }

                // Defensive: jasa & non_stock tidak punya stok fisik — tidak boleh di-FIFO.
                // Set cogs_total = 0 supaya invoice posting bisa lewatkan dengan benar.
                if (in_array($product->sale_type, ['service', 'non_stock'], true)) {
                    $item->update(['cogs_total' => 0, 'cogs_deferred' => false]);
                    continue;
                }

                $isBundle = ($product->sale_type === 'bundle');
                $onHand = $isBundle ? null : $this->inventory->onHand($item->product_id, $delivery->warehouse_id);

                if (!$isBundle && $onHand < (float) $item->qty) {
                    // Stok belum cukup (barang preorder belum selesai produksi):
                    // kirim defer → ledger boleh minus, COGS ditunda sampai invoice.
                    $this->inventory->shipDeferred(
                        $item->product_id,
                        $delivery->warehouse_id,
                        $item->qty,
                        'sales_delivery',
                        $delivery->id,
                        $delivery->sales_order_id
                    );
                    $item->update(['cogs_total' => null, 'cogs_deferred' => true]);
                } else {
                    $cogs = $this->inventory->ship(
                        $item->product_id,
                        $delivery->warehouse_id,
                        $item->qty,
                        'sales_delivery',
                        $delivery->id,
                        $delivery->sales_order_id
                    );
                    $item->update(['cogs_total' => $cogs, 'cogs_deferred' => false]);
                }
            }

            $delivery->update([
                'status' => 'posted',
                'posted_at' => now()
            ]);
        });
    }

    /**
     * Hitung COGS untuk item SJ yang sebelumnya di-defer (stok minus saat post).
     * Dipanggil saat invoice di-post: konsumsi FIFO dari layer aktual yang sudah ada.
     * Melempar (blok invoice) jika layer belum tersedia = produksi belum selesai.
     */
    public function settleDeferredCogs(int $deliveryId): void
    {
        // Bungkus dalam transaksi: bila item ke-2 gagal settle (produksi belum
        // selesai), konsumsi FIFO item ke-1 tidak boleh ter-commit parsial.
        DB::transaction(function () use ($deliveryId) {
            $delivery = SalesDelivery::with('items.product')->findOrFail($deliveryId);

            foreach ($delivery->items as $item) {
                if (!$item->cogs_deferred) {
                    continue;
                }

                $product = $item->product;
                if (!$product || in_array($product->sale_type, ['service', 'non_stock'], true)) {
                    $item->update(['cogs_total' => 0, 'cogs_deferred' => false]);
                    continue;
                }

                try {
                    $cogs = $this->inventory->settleShipmentCogs(
                        $item->product_id,
                        $delivery->warehouse_id,
                        (float) $item->qty,
                        $delivery->id
                    );
                } catch (\Throwable $e) {
                    throw new Exception(
                        "HPP belum bisa dihitung untuk produk \"{$product->name}\" pada Surat Jalan {$delivery->delivery_number}: "
                        . "stok belum tersedia. Selesaikan produksi barang ini terlebih dahulu sebelum membuat invoice."
                    );
                }

                $item->update(['cogs_total' => $cogs, 'cogs_deferred' => false]);
            }
        });
    }
}
