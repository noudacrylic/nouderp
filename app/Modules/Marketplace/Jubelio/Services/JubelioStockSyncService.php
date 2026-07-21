<?php

namespace App\Modules\Marketplace\Jubelio\Services;

use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\Product;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Models\JubelioSyncLog;
use App\Services\BundleService;
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
        protected BundleService $bundles,
    ) {}

    /**
     * Proses semua produk yang ditandai berubah (jubelio_sync_pending).
     * Dipanggil cron sering (mis. tiap 5 menit).
     */
    public function pushPending(int $limit = 200): array
    {
        $stats = ['pushed' => 0, 'skipped' => 0, 'skipped_unmatched' => 0, 'failed' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        Product::where('sync_to_jubelio', true)
            ->where('jubelio_sync_pending', true)
            ->limit($limit)
            ->get()
            ->each(function (Product $p) use (&$stats) {
                // Lepas flag SEBELUM baca stok: bila ada mutasi stok saat push berlangsung,
                // StockMovementObserver men-set pending=true lagi & tidak hilang tertimpa
                // (anti lost-update). Bila push gagal, tandai ulang agar dicoba lagi.
                $p->forceFill(['jubelio_sync_pending' => false])->save();
                $r = $this->pushProduct($p, false);
                $stats[$r]++;
                if ($r === 'failed') {
                    $p->forceFill(['jubelio_sync_pending' => true])->save();
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
        $stats = ['pushed' => 0, 'skipped' => 0, 'skipped_unmatched' => 0, 'failed' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        Product::where('sync_to_jubelio', true)
            ->limit($limit)
            ->get()
            ->each(function (Product $p) use (&$stats) {
                // Lepas flag sebelum push (anti lost-update, lihat pushPending); set ulang bila gagal.
                $p->forceFill(['jubelio_sync_pending' => false])->save();
                $r = $this->pushProduct($p, true);
                $stats[$r]++;
                if ($r === 'failed') {
                    $p->forceFill(['jubelio_sync_pending' => true])->save();
                }
            });

        return $stats;
    }

    /**
     * Dorong stok satu produk ke Jubelio.
     * @return 'pushed'|'skipped'|'skipped_unmatched'|'failed'
     *   - 'skipped'           : stok sudah sama (delta≈0), tidak perlu adjustment.
     *   - 'skipped_unmatched' : tidak bisa diproses (belum ter-match / location belum diatur).
     */
    public function pushProduct(Product $product, bool $reconcile): string
    {
        $setting = JubelioSetting::singleton();

        $itemId = $this->resolveItemId($product);
        if (!$itemId) {
            Log::warning('Jubelio stok: produk tanpa item Jubelio', ['product' => $product->id, 'sku' => $product->sku]);
            JubelioSyncLog::record(JubelioSyncLog::TYPE_STOCK, JubelioSyncLog::SKIP, $product->name, [
                'reference'  => $product->sku,
                'product_id' => $product->id,
                'message'    => 'Produk belum ter-match ke item Jubelio (SKU tidak ditemukan). Jalankan pencocokan produk.',
            ]);
            return 'skipped_unmatched';
        }

        $locationId = $product->jubelio_location_id ?: $setting->default_location_id;
        if (!$locationId) {
            Log::warning('Jubelio stok: location_id belum diatur', ['product' => $product->id]);
            JubelioSyncLog::record(JubelioSyncLog::TYPE_STOCK, JubelioSyncLog::SKIP, $product->name, [
                'reference'  => $product->sku,
                'product_id' => $product->id,
                'message'    => 'Location ID Jubelio belum diatur (Settings → Jubelio).',
            ]);
            return 'skipped_unmatched';
        }

        // Dorong "STOK JUBELIO" = stok fisik (on-hand) DIKURANGI reservasi pesanan
        // NON-marketplace saja. Alasannya:
        //  - Reservasi marketplace TIDAK dikurangi: Jubelio sudah mereservasi pesanannya
        //    sendiri, jadi menguranginya di sini = pengurangan ganda (available Jubelio minus).
        //  - Reservasi non-marketplace (SO dibuat di ERP) TIDAK diketahui Jubelio & belum
        //    memotong stok fisik sampai Surat Jalan terbit → tanpa dikurangi di sini, Jubelio
        //    bisa oversell barang yang sudah dipesan offline. Saat DO terbit, fisik turun &
        //    reservasi hilang, sehingga (fisik − reservasi_nonMP) tetap → cocok kembali.
        // Bundle: hitung dari komponen, kurangi reservasi non-MP komponen (excludeMarketplace).
        //
        // ANTI-OVERSELL (plafon FIFO): stok fisik yang didorong = min(onHand ledger, sisa FIFO
        // layer). Surat Jalan mengonsumsi FIFO layer, jadi apa pun yang melebihi sisa layer TIDAK
        // bisa dipenuhi. Bila ledger & layer drift (mis. entri manual menaikkan ledger tanpa layer),
        // tanpa plafon ini ERP menawarkan stok hantu ke marketplace → oversell berulang + fulfillment
        // gagal "Stock not enough for FIFO consume". min() memastikan tiap unit yang ditawarkan
        // benar-benar bisa dikirim. Lihat [[nouderp-stocklayers-ledger-drift-sj-stuck]].
        if ($product->sale_type === 'bundle') {
            $available = (float) $this->bundles->getBundleStock($product->id, null, false, true);
        } else {
            $onHand    = $this->inventory->onHand($product->id);
            $fifo      = $this->inventory->fifoRemaining($product->id);
            $physical  = min($onHand, $fifo);
            if ($onHand - $fifo > 0.0001) {
                Log::warning('Jubelio stok: drift ledger>FIFO, push diplafon ke FIFO (anti-oversell)', [
                    'product' => $product->id, 'sku' => $product->sku, 'onhand' => $onHand, 'fifo' => $fifo,
                ]);
            }
            $available = round($physical - $this->nonMarketplaceReserved($product->id), 4);
        }

        // Jangan pernah kirim stok negatif ke Jubelio (mis. oversold sebelum DO).
        $available = max(0.0, $available);

        // Produk preorder: tambah buffer kuota preorder (products.preorder_stock) agar bisa
        // dijual melampaui stok fisik. Tanpa ini, preorder yang fisiknya 0 tampak habis.
        if ($product->sale_type === 'preorder') {
            $available += (float) ($product->preorder_stock ?? 0);
        }

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
            JubelioSyncLog::record(JubelioSyncLog::TYPE_STOCK, JubelioSyncLog::FAIL, $product->name, [
                'reference'  => $product->sku,
                'product_id' => $product->id,
                'message'    => $resp['error'] ?: 'Gagal mengirim penyesuaian stok ke Jubelio.',
                'meta'       => ['delta' => $delta, 'available' => $available],
            ]);
            return 'failed';
        }

        $product->forceFill(['jubelio_synced_qty' => $available])->save();
        JubelioSyncLog::record(JubelioSyncLog::TYPE_STOCK, JubelioSyncLog::OK, $product->name, [
            'reference'  => $product->sku,
            'product_id' => $product->id,
            'message'    => ($reconcile ? 'Rekonsiliasi' : 'Push') . ' stok: ' . ($delta > 0 ? '+' : '') . rtrim(rtrim(number_format($delta, 4, '.', ''), '0'), '.') . ' → stok Jubelio ' . rtrim(rtrim(number_format($available, 4, '.', ''), '0'), '.'),
            'meta'       => ['delta' => $delta, 'available' => $available, 'mode' => $reconcile ? 'reconcile' : 'push'],
        ]);
        return 'pushed';
    }

    /**
     * Total reservasi AKTIF produk dari SO NON-marketplace (SO yang TIDAK punya
     * JubelioOrderLink). Reservasi marketplace sengaja dikecualikan agar tidak terjadi
     * pengurangan ganda terhadap reservasi yang sudah dikelola Jubelio sendiri.
     */
    private function nonMarketplaceReserved(int $productId): float
    {
        return (float) \App\Core\Inventory\StockReservation::where('product_id', $productId)
            ->where('status', 'active')
            ->whereNotIn('sales_order_id', function ($q) {
                $q->select('sales_order_id')
                  ->from('jubelio_order_links')
                  ->whereNotNull('sales_order_id');
            })
            ->sum('qty');
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
        if (!$resp['success'] || !is_array($resp['data'])) {
            return null;
        }
        // Respons by-sku/by-id adalah objek ITEM-GROUP; variasi ada di product_skus[].
        // Cocokkan baris yang item_code-nya == SKU produk (case-insensitive, trim),
        // bukan ambil indeks [0] (satu group bisa banyak variasi/SKU berbeda).
        $skus = $resp['data']['product_skus'] ?? null;
        if (is_array($skus)) {
            $needle = mb_strtolower(trim($product->sku));
            foreach ($skus as $row) {
                $code = mb_strtolower(trim((string) ($row['item_code'] ?? '')));
                if ($code !== '' && $code === $needle && isset($row['item_id'])) {
                    $itemId = (int) $row['item_id'];
                    $product->forceFill(['jubelio_item_id' => $itemId])->save();
                    return $itemId;
                }
            }
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
