<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Core\Inventory\Product;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;

/**
 * Tahap 2 — tarik katalog toko Shopee (item + varian) dan petakan ke produk ERP lewat SKU.
 * BACA SAJA terhadap Shopee: tak ada yang dikirim balik.
 *
 * SKU di Shopee berasal dari ERP (didorong Jubelio), jadi cocok-SKU persis menutup
 * sebagian besar peta; sisanya dipasang manual di halaman Peta SKU dan tidak ditimpa lagi.
 */
class ShopeeKatalogService
{
    /** Status item yang ditarik; BANNED/DELETED tidak relevan untuk pesanan baru. */
    public const STATUS = ['NORMAL', 'UNLIST'];

    public function __construct(private ShopeeClient $client) {}

    /** @return array{item: int, varian: int, terpeta: int, belum: int} */
    public function tarik(ShopeeShop $shop): array
    {
        $ids = [];
        foreach (self::STATUS as $status) {
            $offset = 0;
            do {
                $r = $this->client->panggil($shop, 'GET', '/api/v2/product/get_item_list', [
                    'offset' => $offset, 'page_size' => 100, 'item_status' => $status,
                ]);
                foreach ($r['item'] ?? [] as $it) $ids[] = (int) $it['item_id'];
                $offset = (int) ($r['next_offset'] ?? 0);
            } while (!empty($r['has_next_page']));
        }

        $ids = array_values(array_unique($ids));
        $varian = 0;
        $sekarang = now();
        foreach (array_chunk($ids, 50) as $kelompok) {
            $r = $this->client->panggil($shop, 'GET', '/api/v2/product/get_item_base_info', [
                'item_id_list' => implode(',', $kelompok),
            ]);
            foreach ($r['item_list'] ?? [] as $item) {
                $varian += $this->simpanItem($shop, $item, $sekarang);
            }
        }

        $semua = ShopeeItem::where('env', $shop->env)->where('shop_id', $shop->shop_id);
        return [
            'item'    => count($ids),
            'varian'  => $varian,
            'terpeta' => (clone $semua)->whereNotNull('product_id')->count(),
            'belum'   => (clone $semua)->whereNull('product_id')->count(),
        ];
    }

    /** Simpan satu item (dan variannya bila ada). @return int jumlah baris varian */
    private function simpanItem(ShopeeShop $shop, array $item, $sekarang): int
    {
        $itemId = (int) $item['item_id'];
        $dasar  = [
            'item_sku' => $this->bersih($item['item_sku'] ?? null),
            'nama'     => mb_substr((string) ($item['item_name'] ?? ''), 0, 255),
            'status'   => $item['item_status'] ?? null,
        ];

        if (empty($item['has_model'])) {
            $this->simpanBaris($shop, $itemId, 0, $dasar + [
                'model_sku'  => null,
                'nama_model' => null,
                'harga'      => $item['price_info'][0]['current_price'] ?? null,
                'harga_asli' => $item['price_info'][0]['original_price'] ?? null,
                'stok'       => $item['stock_info_v2']['summary_info']['total_available_stock'] ?? null,
            ], $sekarang);
            return 1;
        }

        $r = $this->client->panggil($shop, 'GET', '/api/v2/product/get_model_list', ['item_id' => $itemId]);
        $tier = $r['tier_variation'] ?? [];
        $n = 0;
        foreach ($r['model'] ?? [] as $m) {
            $this->simpanBaris($shop, $itemId, (int) $m['model_id'], $dasar + [
                'model_sku'  => $this->bersih($m['model_sku'] ?? null),
                'nama_model' => $this->namaModel($tier, $m['tier_index'] ?? []),
                'harga'      => $m['price_info'][0]['current_price'] ?? null,
                'harga_asli' => $m['price_info'][0]['original_price'] ?? null,
                'stok'       => $m['stock_info_v2']['summary_info']['total_available_stock'] ?? null,
            ], $sekarang);
            $n++;
        }
        return $n;
    }

    private function simpanBaris(ShopeeShop $shop, int $itemId, int $modelId, array $isi, $sekarang): void
    {
        $baris = ShopeeItem::firstOrNew([
            'env' => $shop->env, 'shop_id' => $shop->shop_id, 'item_id' => $itemId, 'model_id' => $modelId,
        ]);
        $baris->fill($isi + ['disinkron_at' => $sekarang]);

        // Peta manual dipertahankan; peta SKU dihitung ulang (SKU di Shopee bisa diganti).
        if ($baris->peta_sumber !== ShopeeItem::PETA_MANUAL) {
            $produk = ($sku = $baris->sku()) ? Product::where('sku', $sku)->value('id') : null;
            $baris->product_id  = $produk;
            $baris->peta_sumber = $produk ? ShopeeItem::PETA_SKU : null;
        }
        $baris->save();
    }

    /** "Warna: Bening, Ukuran: A4" dari tier_variation + tier_index. */
    private function namaModel(array $tier, array $index): ?string
    {
        $bagian = [];
        foreach ($index as $i => $pilihan) {
            $opsi = $tier[$i]['option_list'][$pilihan]['option'] ?? null;
            if ($opsi !== null) $bagian[] = $opsi;
        }
        return $bagian ? mb_substr(implode(', ', $bagian), 0, 255) : null;
    }

    private function bersih(?string $sku): ?string
    {
        $sku = trim((string) $sku);
        return $sku === '' ? null : mb_substr($sku, 0, 100);
    }
}
