<?php

namespace App\Modules\Marketplace\Jubelio\Services;

use App\Core\Inventory\Product;
use App\Modules\Marketplace\Jubelio\Models\JubelioSyncLog;
use Illuminate\Support\Facades\Log;

/**
 * Fase 3 — pencocokan produk (SKU → item Jubelio) & push harga (ERP sumber kebenaran).
 * Promo TIDAK didorong (tetap diatur di Jubelio). Webhook price/product inbound
 * diabaikan untuk produk tersinkron.
 */
class JubelioProductSyncService
{
    public function __construct(protected JubelioClient $client) {}

    /**
     * Cocokkan produk tersinkron ke item Jubelio via SKU; cache item_id & item_group_id.
     * @return array{matched:int, unmatched:int, unmatched_skus:array<int,string>}
     */
    public function matchAll(bool $onlyMissing = true, int $limit = 1000): array
    {
        $matched = 0; $unmatched = 0; $unmatchedSkus = [];
        if (!$this->client->isReady()) {
            return ['matched' => 0, 'unmatched' => 0, 'unmatched_skus' => []];
        }

        Product::where('sync_to_jubelio', true)
            ->when($onlyMissing, fn ($q) => $q->whereNull('jubelio_item_id'))
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->limit($limit)->get()
            ->each(function (Product $p) use (&$matched, &$unmatched, &$unmatchedSkus) {
                if ($this->matchProduct($p)) {
                    $matched++;
                } else {
                    $unmatched++;
                    $unmatchedSkus[] = $p->sku;
                }
            });

        return ['matched' => $matched, 'unmatched' => $unmatched, 'unmatched_skus' => $unmatchedSkus];
    }

    /** Cocokkan 1 produk; simpan item_id & item_group_id. Return true bila ketemu. */
    public function matchProduct(Product $product): bool
    {
        if (empty($product->sku)) {
            return false;
        }
        $resp = $this->client->getItemBySku($product->sku);
        if (!$resp['success']) {
            return false;
        }
        [$itemId, $groupId] = $this->extractIds($resp['data']);
        if (!$itemId) {
            return false;
        }
        $product->forceFill([
            'jubelio_item_id'       => $itemId,
            'jubelio_item_group_id' => $groupId ?: $product->jubelio_item_group_id,
        ])->save();
        return true;
    }

    /** Proses produk yang harganya berubah (ditandai observer). */
    public function pushPendingPrices(int $limit = 200): array
    {
        $stats = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        Product::where('sync_to_jubelio', true)
            ->where('jubelio_price_pending', true)
            ->limit($limit)->get()
            ->each(function (Product $p) use (&$stats) {
                $r = $this->pushPrice($p);
                $stats[$r]++;
                if ($r !== 'failed') {
                    $p->forceFill(['jubelio_price_pending' => false])->save();
                }
            });

        return $stats;
    }

    /**
     * Dorong harga 1 produk ke Jubelio (harga dasar, store_id -1).
     * @return 'pushed'|'skipped'|'failed'
     */
    public function pushPrice(Product $product): string
    {
        // Pastikan item_id & item_group_id tersedia.
        if (!$product->jubelio_item_id || !$product->jubelio_item_group_id) {
            if (!$this->matchProduct($product)) {
                JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::SKIP, $product->name, [
                    'reference'  => $product->sku,
                    'product_id' => $product->id,
                    'message'    => 'Produk belum ter-match ke item Jubelio (SKU tidak ditemukan).',
                ]);
                return 'skipped';
            }
            $product->refresh();
        }
        if (!$product->jubelio_item_id || !$product->jubelio_item_group_id) {
            return 'skipped';
        }

        $price = (float) $product->display_price;
        if ($price <= 0) {
            JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::SKIP, $product->name, [
                'reference'  => $product->sku,
                'product_id' => $product->id,
                'message'    => 'Harga utama produk belum diatur (0).',
            ]);
            return 'skipped';
        }

        $resp = $this->client->updatePrices([[
            'item_group_id' => (int) $product->jubelio_item_group_id,
            'item_id'       => (int) $product->jubelio_item_id,
            'price'         => $price,
        ]]);

        if (!$resp['success']) {
            Log::warning('Jubelio harga: push gagal', ['product' => $product->id, 'error' => $resp['error']]);
            JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::FAIL, $product->name, [
                'reference'  => $product->sku,
                'product_id' => $product->id,
                'message'    => $resp['error'] ?: 'Gagal mengirim harga ke Jubelio.',
                'meta'       => ['price' => $price],
            ]);
            return 'failed';
        }
        JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::OK, $product->name, [
            'reference'  => $product->sku,
            'product_id' => $product->id,
            'message'    => 'Harga utama dikirim: ' . number_format($price, 0, ',', '.'),
            'meta'       => ['price' => $price],
        ]);
        return 'pushed';
    }

    /**
     * Dorong harga KHUSUS TOKO (bukan harga dasar) — dipakai Analisa ▸ Harga Produk supaya
     * tiap marketplace boleh berharga beda sesuai potongannya masing-masing.
     *
     * Harga per toko menimpa harga dasar di Jubelio; harga dasar sendiri tidak disentuh,
     * jadi toko yang tidak dikirimi harga khusus tetap ikut harga web. Satu kanal bisa
     * punya lebih dari satu toko (TikTok & Tokopedia) — semuanya dikirimi harga yang sama
     * dalam satu panggilan, supaya tidak ada toko yang tertinggal separuh jalan.
     *
     * @param array<int,int> $storeIds store_id Jubelio tujuan
     * @return array{ok:bool, message:string}
     */
    public function pushStorePrice(Product $product, array $storeIds, float $price): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));

        if (empty($storeIds)) {
            return ['ok' => false, 'message' => 'Kanal ini belum punya toko Jubelio yang dipetakan.'];
        }
        if ($price <= 0) {
            return ['ok' => false, 'message' => 'Harga belum diisi.'];
        }

        if (!$product->jubelio_item_id || !$product->jubelio_item_group_id) {
            if (!$this->matchProduct($product)) {
                $message = 'Produk belum ter-match ke item Jubelio (SKU tidak ditemukan).';
                JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::SKIP, $product->name, [
                    'reference'  => $product->sku,
                    'product_id' => $product->id,
                    'message'    => $message,
                ]);

                return ['ok' => false, 'message' => $message];
            }
            $product->refresh();
        }

        $resp = $this->client->updatePrices(array_map(fn ($storeId) => [
            'item_group_id' => (int) $product->jubelio_item_group_id,
            'item_id'       => (int) $product->jubelio_item_id,
            'price'         => $price,
            'store_id'      => (string) $storeId,
        ], $storeIds));

        $stores = implode(', ', $storeIds);

        if (!$resp['success']) {
            Log::warning('Jubelio harga toko: push gagal', ['product' => $product->id, 'stores' => $storeIds, 'error' => $resp['error']]);
            JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::FAIL, $product->name, [
                'reference'  => $product->sku,
                'product_id' => $product->id,
                'message'    => $resp['error'] ?: 'Gagal mengirim harga toko ke Jubelio.',
                'meta'       => ['price' => $price, 'store_ids' => $storeIds],
            ]);

            return ['ok' => false, 'message' => $resp['error'] ?: 'Gagal mengirim harga ke Jubelio.'];
        }

        JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::OK, $product->name, [
            'reference'  => $product->sku,
            'product_id' => $product->id,
            'message'    => 'Harga toko (' . $stores . ') dikirim: ' . number_format($price, 0, ',', '.'),
            'meta'       => ['price' => $price, 'store_ids' => $storeIds],
        ]);

        return ['ok' => true, 'message' => 'Harga ' . number_format($price, 0, ',', '.') . ' terkirim ke toko ' . $stores . '.'];
    }

    /** Ekstrak [item_id, item_group_id] dari respons item Jubelio (beberapa bentuk). */
    private function extractIds($data): array
    {
        if (!is_array($data)) {
            return [null, null];
        }
        $node = $data;
        if (isset($data['items'][0]) && is_array($data['items'][0])) {
            $node = $data['items'][0];
        } elseif (isset($data['data'][0]) && is_array($data['data'][0])) {
            $node = $data['data'][0];
        }
        $itemId  = $node['item_id'] ?? $data['item_id'] ?? null;
        $groupId = $node['item_group_id'] ?? $data['item_group_id'] ?? null;
        return [$itemId ? (int) $itemId : null, $groupId ? (int) $groupId : null];
    }
}
