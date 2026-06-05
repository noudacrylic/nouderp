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
                $delivery = SalesDelivery::create([
                    'delivery_number' => NumberGeneratorService::generate('DOINV'),
                    'invoice_id'      => $invoice->id,
                    'sales_order_id'  => $invoice->sales_order_id ?? null,
                    'reference_type'  => 'sales_invoice',
                    'reference_id'    => $invoice->id,
                    'warehouse_id'    => $invoice->warehouse_id,
                    'delivery_date'   => $invoice->invoice_date,
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
