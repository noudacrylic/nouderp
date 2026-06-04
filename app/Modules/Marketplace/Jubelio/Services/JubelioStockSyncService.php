<?php

namespace App\Modules\Marketplace\Jubelio\Services;

use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\Product;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use Illuminate\Support\Facades\Log;

/**
 * Push stok ERP → Jubelio (ERP = sumber kebenaran). Referensi = stok AVAILABLE
 * (sudah dikurangi reservasi SO), bukan stok fisik.
 *
 * Stok di Jubelio diubah via adjustment DELTA. Baseline delta:
 *  - mode normal  : products.jubelio_synced_qty (nilai yang ERP tahu ada di Jubelio).
 *  - mode reconcile: GET stok aktual Jubelio (koreksi drift), fallback ke baseline lokal.
 *
 * Karena ERP satu-satunya penulis stok Jubelio (webhook stok inbound diabaikan),
 * baseline lokal cukup akurat untuk push harian; reconcile 2 jam menambal drift.
 */
class JubelioStockSyncService
{
    /** Cache bin default per location_id selama satu proses. */
    private array $binCache = [];

    public function __construct(
        protected JubelioClient $client,
        protected InventoryEngine $inventory,
    ) {}

    /**
     * Proses semua produk yang ditandai berubah (jubelio_sync_pending).
     * Dipanggil cron sering (mis. tiap 5 menit).
     */
    public function pushPending(int $limit = 200): array
    {
        $stats = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        Product::where('sync_to_jubelio', true)
            ->where('jubelio_sync_pending', true)
            ->limit($limit)
            ->get()
            ->each(function (Product $p) use (&$stats) {
                $r = $this->pushProduct($p, false);
                $stats[$r]++;
                // Lepas flag hanya bila sukses / memang tak perlu (skipped); biarkan bila gagal agar dicoba lagi.
                if ($r !== 'failed') {
                    $p->forceFill(['jubelio_sync_pending' => false])->save();
                }
            });

        return $stats;
    }

    /**
     * Rekonsiliasi penuh: bandingkan semua produk tersinkron terhadap stok aktual Jubelio.
     * Dipanggil cron 2 jam sekali. ERP menang.
     */
    public function reconcileAll(int $limit = 1000): array
    {
        $stats = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        Product::where('sync_to_jubelio', true)
            ->limit($limit)
            ->get()
            ->each(function (Product $p) use (&$stats) {
                $r = $this->pushProduct($p, true);
                $stats[$r]++;
                if ($r !== 'failed') {
                    $p->forceFill(['jubelio_sync_pending' => false])->save();
                }
            });

        return $stats;
    }

    /**
     * Dorong stok satu produk ke Jubelio.
     * @return 'pushed'|'skipped'|'failed'
     */
    public function pushProduct(Product $product, bool $reconcile): string
    {
        $setting = JubelioSetting::singleton();

        $itemId = $this->resolveItemId($product);
        if (!$itemId) {
            Log::warning('Jubelio stok: produk tanpa item Jubelio', ['product' => $product->id, 'sku' => $product->sku]);
            return 'skipped';
        }

        $locationId = $product->jubelio_location_id ?: $setting->default_location_id;
        if (!$locationId) {
            Log::warning('Jubelio stok: location_id belum diatur', ['product' => $product->id]);
            return 'skipped';
        }

        $available = round($this->inventory->availableStock($product->id), 4);

        // Baseline untuk hitung delta.
        $baseline = $product->jubelio_synced_qty !== null ? (float) $product->jubelio_synced_qty : null;
        if ($reconcile || $baseline === null) {
            $remote = $this->client->getItemAvailable($itemId, $locationId);
            if ($remote !== null) {
                $baseline = $remote;
            } elseif ($baseline === null) {
                $baseline = 0.0; // belum diketahui — anggap 0 (Jubelio akan diset = available)
            }
        }

        $delta = round($available - $baseline, 4);
        if (abs($delta) < 0.0001) {
            // Sudah sinkron; pastikan baseline tersimpan.
            if ($product->jubelio_synced_qty === null || (float) $product->jubelio_synced_qty !== $available) {
                $product->forceFill(['jubelio_synced_qty' => $available])->save();
            }
            return 'skipped';
        }

        $binId = $this->defaultBin($locationId);
        $cost  = (float) ($product->last_cost ?: $product->cost_price ?: 0);

        $resp = $this->client->postAdjustment($locationId, [[
            'item_id'     => $itemId,
            'qty_in_base' => $delta,
            'cost'        => $cost,
            'bin_id'      => $binId,
            'unit'        => $product->base_unit ?: 'Pcs',
        ]], 'Sinkron stok Noud ERP (available)');

        if (!$resp['success']) {
            Log::warning('Jubelio stok: adjustment gagal', ['product' => $product->id, 'delta' => $delta, 'error' => $resp['error']]);
            return 'failed';
        }

        $product->forceFill(['jubelio_synced_qty' => $available])->save();
        return 'pushed';
    }

    /** Resolusi item_id Jubelio dari produk (cache di kolom; fallback via SKU). */
    private function resolveItemId(Product $product): ?int
    {
        if ($product->jubelio_item_id) {
            return (int) $product->jubelio_item_id;
        }
        if (empty($product->sku)) {
            return null;
        }
        $resp = $this->client->getItemBySku($product->sku);
        if (!$resp['success']) {
            return null;
        }
        $data = $resp['data'];
        $itemId = $data['item_id'] ?? ($data['items'][0]['item_id'] ?? null);
        if ($itemId) {
            $product->forceFill(['jubelio_item_id' => (int) $itemId])->save();
            return (int) $itemId;
        }
        return null;
    }

    private function defaultBin(int $locationId): int
    {
        if (array_key_exists($locationId, $this->binCache)) {
            return $this->binCache[$locationId];
        }
        $resp = $this->client->getDefaultBin($locationId);
        $bin = 0;
        if ($resp['success'] && is_array($resp['data'])) {
            $bin = (int) ($resp['data']['bin_id'] ?? ($resp['data'][0]['bin_id'] ?? 0));
        }
        return $this->binCache[$locationId] = $bin;
    }
}
