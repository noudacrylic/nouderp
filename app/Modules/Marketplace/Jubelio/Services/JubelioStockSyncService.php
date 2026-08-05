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
 * Yang disamakan adalah STOK FISIK, dengan invarian:
 *     fisik Jubelio == fisik ERP − pesanan yang hanya ada di ERP
 * Jadi fisik ERP dan fisik Jubelio memang sengaja BERBEDA. Pesanan webstore/ERP belum
 * menurunkan fisik ERP (fisik baru turun saat Surat Jalan) tapi langsung memotong fisik
 * Jubelio, supaya stok yang TERSEDIA dijual tetap sama di kedua sisi.
 *
 * Kapan stok didorong (sisanya tidak perlu didorong sama sekali):
 *  1. Pesanan Jubelio  → TIDAK didorong untuk item yang dipesan itu sendiri: Jubelio kirim
 *     detail pesanan ke ERP, kedua sisi memotong sendiri-sendiri. Mendorongnya balik = potong
 *     dua kali (lihat loop-prevention di InventoryLedgerObserver & StockReservationObserver).
 *     PENGECUALIAN: pesanan BUNDLE. Jubelio menahan item bundle, sedangkan ERP mereservasi
 *     KOMPONEN — item komponen di Jubelio tidak tersentuh, jadi komponen (dan bundle lain yang
 *     memakainya) TETAP harus didorong. Lihat JubelioOrderLink::coveredReservationQty.
 *  2. Pesanan webstore/ERP → didorong (fisik Jubelio dipotong sebesar reservasi non-marketplace).
 *  3. Penjualan bundle → didorong: bundle hanya ada di ERP, stoknya tak dikenal Jubelio.
 *  4. Produksi selesai  → didorong (ledger 'production_order').
 *  5. Stok opname       → didorong (ledger 'adjustment_in'/'adjustment_out').
 *  6. Transfer stok     → didorong (ledger 'transfer_in'/'transfer_out').
 *
 * Stok di Jubelio diubah via adjustment DELTA, jadi delta HARUS dihitung dari kondisi Jubelio
 * yang benar-benar diukur (GET end_qty) tiap kali kita menulis. `products.jubelio_synced_qty`
 * cuma CACHE hasil push terakhir (asumsi, bukan pengukuran) dan dipakai sebatas penyaring murah
 * agar push yang tak mengubah apa pun tidak menembak HTTP. Cache di-null-kan tiap kali gagal.
 * Menebak baseline DILARANG — lihat alasannya di pushProduct(). Cron reconcile 2 jam tetap
 * jadi jaring pengaman untuk drift yang lolos penyaring.
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

        // Dorong "STOK JUBELIO" = stok fisik (on-hand) DIKURANGI reservasi yang BELUM
        // tercermin di Jubelio. Yang dikecualikan hanya reservasi yang pasangannya ditahan
        // Jubelio atas ITEM YANG SAMA (pesanan marketplace dengan baris atas produk ini):
        //  - Kalau ikut dikurangi, satu pesanan terpotong dua kali (available Jubelio minus).
        //  - Reservasi lain (SO dibuat di ERP, atau reservasi komponen yang lahir dari pesanan
        //    BUNDLE) TIDAK diketahui Jubelio pada item ini & belum memotong stok fisik sampai
        //    Surat Jalan terbit → tanpa dikurangi di sini, Jubelio oversell barang yang sudah
        //    dijanjikan. Saat DO terbit, fisik turun & reservasi hilang sehingga hasilnya tetap
        //    → cocok kembali. Lihat JubelioOrderLink::coveredReservationQty untuk aturannya.
        // Bundle: hitung dari komponen; reservasi komponen yang berasal dari pesanan marketplace
        // atas bundle ini yang dikecualikan (excludeMarketplaceReserved).
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
            $available = round($physical - $this->reservedNotHeldByJubelio($product->id), 4);
        }

        // Jangan pernah kirim stok negatif ke Jubelio (mis. oversold sebelum DO).
        $available = max(0.0, $available);

        // Produk preorder: tambah buffer kuota preorder (products.preorder_stock) agar bisa
        // dijual melampaui stok fisik. Tanpa ini, preorder yang fisiknya 0 tampak habis.
        if ($product->sale_type === 'preorder') {
            $available += (float) ($product->preorder_stock ?? 0);
        }

        // ─────────────────────── Baseline untuk hitung delta ───────────────────────
        // Adjustment Jubelio bersifat DELTA terhadap saldo FISIK di sana, jadi angka yang
        // dikirim hanya benar bila kita tahu PERSIS isi Jubelio saat ini. Invarian targetnya:
        //     fisik Jubelio == fisik ERP − pesanan yang hanya ada di ERP   (== $available)
        // Fisik ERP dan fisik Jubelio memang SENGAJA berbeda (SO webstore/ERP memotong Jubelio
        // lebih dulu supaya stok TERSEDIA sama, padahal fisik ERP baru turun saat Surat Jalan),
        // sehingga baseline TIDAK BOLEH direkonstruksi dari stok fisik ERP. Satu-satunya sumber
        // sah = GET end_qty Jubelio.
        //
        // `jubelio_synced_qty` hanyalah CACHE hasil push terakhir — sebuah asumsi, bukan hasil
        // pengukuran. Ia rutin basi karena Jubelio bergerak sendiri untuk hal yang sengaja tidak
        // kita dorong (pesanan marketplace memotong stoknya sendiri, retur/cancel, edit manual
        // di dashboard). Karena itu cache dipakai HANYA sebagai penyaring murah: kalau cache
        // bilang tidak ada yang berubah, lewati tanpa HTTP (drift-nya ditambal cron reconcile).
        // Begitu kita benar-benar akan MENULIS, wajib ukur ulang dulu.
        $cached = $product->jubelio_synced_qty !== null ? (float) $product->jubelio_synced_qty : null;
        if (!$reconcile && $cached !== null && abs($available - $cached) < 0.0001) {
            return 'skipped';
        }

        // Ukur kondisi Jubelio sekarang. Delta selalu dihitung dari hasil ukur ini, sehingga
        // hasil akhirnya dijamin == $available (yang sudah di-clamp ≥ 0) — mustahil mendorong
        // stok Jubelio ke bawah nol, penyebab error 500 beruntun yang dulu terjadi.
        $baseline = $this->client->getItemAvailable($itemId, $locationId);
        if ($baseline === null) {
            // Stok Jubelio tak terbaca → BATALKAN, jangan menebak. Dulu di sini di-anggap 0;
            // itu berbahaya karena delta jadi = SELURUH stok available dan DITAMBAHKAN ke saldo
            // fisik Jubelio yang sudah ada → stok dobel → oversell + valuasi Jubelio kacau
            // (adjustment membawa cost). Tak bisa ditambal dengan "larang delta positif":
            // delta positif itu sah (produksi selesai, opname naik, SO lokal di-void).
            // Baseline di-null-kan agar percobaan berikutnya mengukur lagi, bukan menebak.
            $product->forceFill(['jubelio_synced_qty' => null])->save();
            Log::warning('Jubelio stok: gagal membaca stok Jubelio — push dibatalkan (anti tebak-0)', [
                'product' => $product->id, 'sku' => $product->sku, 'item_id' => $itemId,
            ]);
            JubelioSyncLog::record(JubelioSyncLog::TYPE_STOCK, JubelioSyncLog::FAIL, $product->name, [
                'reference'  => $product->sku,
                'product_id' => $product->id,
                'message'    => 'Stok Jubelio tidak terbaca — push dibatalkan agar stok tidak dobel. Akan dicoba lagi otomatis.',
                'meta'       => ['available' => $available, 'baseline' => null],
            ]);
            return 'failed';
        }

        $delta = round($available - $baseline, 4);
        if (abs($delta) < 0.0001) {
            // Sudah sinkron; pastikan cache menyimpan hasil ukur.
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
            // Batalkan cache: setelah gagal kita TIDAK tahu lagi isi Jubelio (adjustment bisa
            // saja sebagian masuk / balasannya timeout). Membiarkan cache lama membuat penyaring
            // murah di atas bisa salah menyimpulkan "tidak ada perubahan" dan melewatkan koreksi.
            $product->forceFill(['jubelio_synced_qty' => null])->save();
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
     * Push SEKETIKA (sinkron) sekumpulan produk ke Jubelio — dipakai agar stok komponen
     * bundle langsung berkurang di marketplace begitu bundle terjual & SJ terbentuk, tanpa
     * menunggu cron 5 menit (celah oversell). Diberi "seed" = produk pada SO; method meng-
     * expand ke: komponen bila seed adalah bundle, LALU bundle lain yang berbagi komponen
     * tsb (stok tersedia-nya ikut berubah). Idempoten & aman: pushProduct menangkap errornya
     * sendiri; produk gagal ditandai ulang agar cron mencoba lagi. Cron push tetap jaring
     * pengaman. Lihat [[nouderp-stocklayers-ledger-drift-sj-stuck]] & observer stok.
     *
     * @param  int[]  $seedProductIds
     * @return array{pushed:int, skipped:int, failed:int}
     */
    public function pushProductsNow(array $seedProductIds): array
    {
        $stats = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        if (empty($seedProductIds) || !$this->client->isReady()) {
            return $stats;
        }

        $ids = collect($seedProductIds)->filter()->unique();

        // Seed bundle → tambahkan komponennya.
        $components = \App\Core\Inventory\BundleComponent::whereIn('bundle_product_id', $ids)->pluck('component_product_id')
            ->merge(\App\Core\Inventory\ProductBundle::whereIn('bundle_product_id', $ids)->pluck('component_product_id'));
        $ids = $ids->merge($components)->unique();

        // Semua produk di atas → tambahkan bundle lain yang memuatnya sebagai komponen.
        $bundles = \App\Core\Inventory\BundleComponent::whereIn('component_product_id', $ids)->pluck('bundle_product_id')
            ->merge(\App\Core\Inventory\ProductBundle::whereIn('component_product_id', $ids)->pluck('bundle_product_id'));
        $ids = $ids->merge($bundles)->unique()->values();

        Product::whereIn('id', $ids)->where('sync_to_jubelio', true)->get()->each(function (Product $p) use (&$stats) {
            // Lepas flag sebelum push (anti lost-update, sama pola pushPending); set ulang bila gagal.
            $p->forceFill(['jubelio_sync_pending' => false])->save();
            $r = $this->pushProduct($p, false);
            $stats[$r === 'skipped_unmatched' ? 'skipped' : $r]++;
            if ($r === 'failed') {
                $p->forceFill(['jubelio_sync_pending' => true])->save();
            }
        });

        return $stats;
    }

    /**
     * Reservasi AKTIF produk yang BELUM tercermin di Jubelio — inilah yang harus dipotong
     * dari stok fisik sebelum didorong.
     *
     * Yang dikecualikan hanya reservasi yang pasangannya benar-benar ditahan Jubelio atas
     * ITEM YANG SAMA, yaitu pesanan marketplace dengan baris atas produk ini sendiri.
     * Pengecualian versi lama berlaku untuk SELURUH pesanan marketplace, dan itu bocor pada
     * bundle: pembeli memesan item bundle (Jubelio menahan item bundle), sementara reservasi
     * ERP jatuh di KOMPONEN. Komponen jadi ikut dikecualikan padahal Jubelio tak pernah
     * menahannya → komponen tetap ditawarkan penuh → barang yang sama dijanjikan dua kali
     * (5 bundle + 10 satuan dari 10 unit fisik) sampai stok tersedia minus dalam.
     */
    private function reservedNotHeldByJubelio(int $productId): float
    {
        $reserved = (float) \App\Core\Inventory\StockReservation::where('product_id', $productId)
            ->where('status', 'active')
            ->sum('qty');

        $covered = \App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink::coveredReservationQty(
            $productId,
            $productId
        );

        return max(0.0, round($reserved - $covered, 4));
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
