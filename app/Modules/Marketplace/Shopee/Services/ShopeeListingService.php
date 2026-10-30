<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Core\Inventory\Product;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;

/**
 * Stok & berat listing Shopee, ditulis LANGSUNG lewat API Shopee — untuk produk yang tak
 * disinkron ke Jubelio (produk custom CS* hanya dijual di Shopee). Produk yang stoknya
 * dipegang Jubelio JANGAN lewat sini: Jubelio menimpanya lagi tiap ada pesanan.
 *
 * Seperti harga (ShopeeHargaService), hasilnya dibaca balik dari Shopee.
 */
class ShopeeListingService
{
    public function __construct(private ShopeeClient $client, private ShopeeHargaService $harga) {}

    /** @return array{ok:bool, pesan:string} */
    public function kirimStok(Product $product, int $stok): array
    {
        [$shop, $varian, $tolak] = $this->siapkan($product);
        if ($tolak) return ['ok' => false, 'pesan' => $tolak];

        $gagal = [];
        foreach ($varian->groupBy('item_id') as $itemId => $baris) {
            try {
                $r = $this->client->panggil($shop, 'POST', '/api/v2/product/update_stock', [
                    'item_id'    => (int) $itemId,
                    'stock_list' => $baris->map(fn ($v) => [
                        'model_id'     => (int) $v->model_id,
                        'seller_stock' => [['stock' => $stok]],
                    ])->values()->all(),
                ]);
                foreach ($r['failure_list'] ?? [] as $f) {
                    $gagal[] = ($f['failed_reason'] ?? 'ditolak');
                }
            } catch (ShopeeException $e) {
                $gagal[] = $e->getMessage();
            }
        }

        $this->harga->segarkan($shop, $varian->pluck('item_id')->unique()->values()->all());
        $beda = ShopeeItem::terpetaKe($shop, $product)->filter(fn ($v) => (int) $v->stok !== $stok);

        if (!$gagal && $beda->isEmpty()) {
            return ['ok' => true, 'pesan' => "Stok Shopee kini {$stok} — dicek balik langsung ke Shopee."];
        }

        return ['ok' => false, 'pesan' => 'Stok Shopee: ' . implode(' · ', array_filter([
            $gagal ? 'gagal — ' . implode('; ', $gagal) : null,
            $beda->isNotEmpty() ? 'saat dicek balik tertulis ' . $beda->map(fn ($v) => (int) $v->stok)->unique()->implode('/') : null,
        ]))];
    }

    /**
     * Berat dikirim per LISTING (Shopee menyimpan berat di tingkat item). Hanya listing
     * tanpa varian yang disentuh: mengubah berat listing ber-varian ikut mengubah varian
     * lain yang bukan produk ini.
     *
     * @return array{ok:bool, pesan:string}
     */
    public function kirimBerat(Product $product, int $gram): array
    {
        [$shop, $varian, $tolak] = $this->siapkan($product);
        if ($tolak) return ['ok' => false, 'pesan' => $tolak];

        $tunggal = $varian->where('model_id', 0);
        if ($tunggal->isEmpty()) {
            return ['ok' => false, 'pesan' => 'Berat tidak dikirim ke Shopee: listingnya ber-varian, ubah di Seller Centre.'];
        }

        $kg = round($gram / 1000, 3);
        $gagal = [];
        foreach ($tunggal->pluck('item_id')->unique() as $itemId) {
            try {
                $this->client->panggil($shop, 'POST', '/api/v2/product/update_item', ['item_id' => (int) $itemId, 'weight' => $kg]);
            } catch (ShopeeException $e) {
                $gagal[] = $e->getMessage();
            }
        }

        $this->harga->segarkan($shop, $tunggal->pluck('item_id')->unique()->values()->all());
        $beda = ShopeeItem::terpetaKe($shop, $product)->where('model_id', 0)
            ->filter(fn ($v) => $v->berat_kg === null || abs((float) $v->berat_kg - $kg) > 0.0005);

        if (!$gagal && $beda->isEmpty()) {
            return ['ok' => true, 'pesan' => 'Berat Shopee ' . rtrim(rtrim(number_format($kg, 3, ',', '.'), '0'), ',') . ' kg.'];
        }

        return ['ok' => false, 'pesan' => 'Berat Shopee: ' . ($gagal ? 'gagal — ' . implode('; ', $gagal) : 'saat dicek balik masih berbeda.')];
    }

    /** @return array{0:?ShopeeShop, 1:\Illuminate\Support\Collection, 2:?string} */
    private function siapkan(Product $product): array
    {
        if (!$this->client->siap() || !($shop = ShopeeShop::aktif())) {
            return [null, collect(), 'Toko Shopee belum terhubung.'];
        }
        $varian = ShopeeItem::terpetaKe($shop, $product);

        return [$shop, $varian, $varian->isEmpty() ? 'Produk ini tidak terpeta ke listing Shopee aktif (Peta SKU).' : null];
    }
}
