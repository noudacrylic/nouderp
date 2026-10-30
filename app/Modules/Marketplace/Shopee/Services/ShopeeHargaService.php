<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Core\Inventory\Product;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;

/**
 * Shopee tahap 3b — ubah harga NORMAL (original_price) sebuah produk ERP langsung di Shopee.
 *
 * Satu produk bisa terjual di beberapa listing/varian (peta SKU); semuanya disamakan.
 * Sesudah dikirim, harganya langsung dibaca balik dari Shopee dan shopee_items diperbarui —
 * "berhasil" berarti Shopee memang sudah memegang harga itu, bukan sekadar permintaan
 * diterima. Promo yang sedang berjalan tidak disentuh: Shopee memotongnya dari harga normal.
 *
 * Jubelio TIDAK diurus di sini; pemanggil yang memutuskan ikut mengirim ke Jubelio supaya
 * Jubelio tak menimpa balik dengan harga lama (selama pesanan Shopee masih lewat Jubelio).
 */
class ShopeeHargaService
{
    public function __construct(private ShopeeClient $client) {}

    /**
     * @return array{ok:bool, sesuai:bool, listing:int, gagal:array<int,string>, pesan:string, tanpa_listing:bool}
     */
    public function kirim(Product $product, float $harga): array
    {
        $hasil = ['ok' => false, 'sesuai' => false, 'listing' => 0, 'gagal' => [], 'pesan' => '', 'tanpa_listing' => false];

        if ($harga <= 0) {
            return ['pesan' => 'Harga belum diisi.'] + $hasil;
        }
        if (!$this->client->siap() || !($shop = ShopeeShop::aktif())) {
            return ['pesan' => 'Toko Shopee belum terhubung.'] + $hasil;
        }

        $baris = ShopeeItem::terpetaKe($shop, $product);
        if ($baris->isEmpty()) {
            return ['pesan' => 'Produk ini tidak terpeta ke listing Shopee aktif (Peta SKU).', 'tanpa_listing' => true] + $hasil;
        }

        $perItem = $baris->groupBy('item_id');
        foreach ($perItem as $itemId => $varian) {
            try {
                $r = $this->client->panggil($shop, 'POST', '/api/v2/product/update_price', [
                    'item_id'    => (int) $itemId,
                    'price_list' => $varian->map(fn ($v) => ['model_id' => (int) $v->model_id, 'original_price' => (float) $harga])->values()->all(),
                ]);
                foreach ($r['failure_list'] ?? [] as $f) {
                    $hasil['gagal'][] = $this->label($varian, (int) ($f['model_id'] ?? 0)) . ': ' . ($f['failed_reason'] ?? 'ditolak');
                }
            } catch (ShopeeException $e) {
                $hasil['gagal'][] = $varian->first()->nama . ': ' . $e->getMessage();
            }
        }

        // Dibaca balik dari Shopee, bukan dari jawaban update_price.
        $this->segarkan($shop, $perItem->keys()->map(fn ($i) => (int) $i)->all());
        $terbaca = ShopeeItem::terpetaKe($shop, $product);
        $beda    = $terbaca->filter(fn ($v) => $v->harga_asli === null || abs((float) $v->harga_asli - $harga) >= 1);

        $hasil['listing'] = $perItem->count();
        $hasil['sesuai']  = $beda->isEmpty();
        $hasil['ok']      = empty($hasil['gagal']) && $hasil['sesuai'];
        $rp = 'Rp' . number_format($harga, 0, ',', '.');
        $hasil['pesan'] = $hasil['ok']
            ? "Shopee: {$rp} berlaku di {$hasil['listing']} listing ({$terbaca->count()} varian) — dicek balik langsung ke Shopee."
            : 'Shopee: ' . implode(' · ', array_filter([
                $hasil['gagal'] ? 'gagal — ' . implode('; ', $hasil['gagal']) : null,
                !$hasil['sesuai'] ? 'saat dicek balik masih berbeda di ' . $beda->map(fn ($v) => $v->namaLengkap() . ' (' . ($v->harga_asli === null ? '—' : 'Rp' . number_format((float) $v->harga_asli, 0, ',', '.')) . ')')->implode(', ') : null,
            ]));

        return $hasil;
    }

    /** Perbarui harga, stok & berat shopee_items untuk item-item ini langsung dari Shopee. */
    public function segarkan(ShopeeShop $shop, array $itemIds): void
    {
        try {
            $r = $this->client->panggil($shop, 'GET', '/api/v2/product/get_item_base_info', ['item_id_list' => implode(',', $itemIds)]);
        } catch (ShopeeException) {
            return;
        }

        foreach ($r['item_list'] ?? [] as $item) {
            $itemId = (int) $item['item_id'];
            $harga  = [];
            $stok   = [];
            if (empty($item['has_model'])) {
                $harga[0] = $item['price_info'][0] ?? [];
                $stok[0]  = $item['stock_info_v2']['summary_info']['total_available_stock'] ?? null;
            } else {
                try {
                    $m = $this->client->panggil($shop, 'GET', '/api/v2/product/get_model_list', ['item_id' => $itemId]);
                } catch (ShopeeException) {
                    continue;
                }
                foreach ($m['model'] ?? [] as $model) {
                    $harga[(int) $model['model_id']] = $model['price_info'][0] ?? [];
                    $stok[(int) $model['model_id']]  = $model['stock_info_v2']['summary_info']['total_available_stock'] ?? null;
                }
            }
            foreach ($harga as $modelId => $p) {
                ShopeeItem::where('env', $shop->env)->where('shop_id', $shop->shop_id)
                    ->where('item_id', $itemId)->where('model_id', $modelId)
                    ->update([
                        'harga'        => $p['current_price'] ?? null,
                        'harga_asli'   => $p['original_price'] ?? null,
                        'stok'         => $stok[$modelId] ?? null,
                        'berat_kg'     => isset($item['weight']) ? (float) $item['weight'] : null,
                        'disinkron_at' => now(),
                    ]);
            }
        }
    }

    private function label($varian, int $modelId): string
    {
        return $varian->firstWhere('model_id', $modelId)?->namaLengkap() ?? $varian->first()->nama;
    }
}
