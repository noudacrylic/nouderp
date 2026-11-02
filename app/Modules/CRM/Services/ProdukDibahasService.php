<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;

/**
 * "Fokus" chat Shopee seperti di Seller Centre: kartu produk terakhir yang dikirim pembeli (chat
 * dari halaman produk) → listing + SKU variannya. Kartu lebih tua dari 7 hari dianggap sudah bukan
 * topik. Kartu `item` hanya membawa item_id, jadi varian yang dimaksud tidak diketahui dan semua
 * SKU ditunjukkan. Kartu `variation_card` ("Pelanggan menanyakan tentang variasi") membawa model_id,
 * jadi variannya pasti.
 */
class ProdukDibahasService
{
    public const JENIS_KARTU = ['item', 'variation_card'];

    /** SKU yang ditulis di layar; sisanya jadi "+n lainnya" dan tetap terbaca di title. */
    private const MAKS_SKU_TAMPIL = 4;

    /** @return array{item_id:int, nama:string, sent_at:\Illuminate\Support\Carbon, varian:\Illuminate\Support\Collection, pilihan:?ShopeeItem}|null */
    public function untukChat(CrmConversation $chat): ?array
    {
        if (!$chat->shopee()) return null;

        $kartu = CrmMessage::where('conversation_id', $chat->id)
            ->where('direction', CrmMessage::MASUK)
            ->whereIn('raw->message_type', self::JENIS_KARTU)
            ->where('sent_at', '>=', now()->subDays(7))
            ->latest('sent_at')->latest('id')->first();
        $itemId = $this->itemId($kartu?->raw);
        if (!$itemId) return null;

        $varian = ShopeeItem::where('shop_id', (int) $chat->business_number_id)->where('item_id', $itemId)
            ->orderBy('model_id')->get(['nama', 'nama_model', 'model_id', 'model_sku', 'item_sku', 'stok']);
        if ($varian->isEmpty()) return null;

        return [
            'item_id' => $itemId,
            'nama'    => (string) $varian->first()->nama,
            'sent_at' => $kartu->sent_at,
            'varian'  => $varian,
            'pilihan' => $varian->firstWhere('model_id', (int) data_get($kartu->raw, 'content.model_id')),
        ];
    }

    /** @return string[] SKU unik varian, urut seperti listing */
    public function daftarSku(iterable $varian): array
    {
        $sku = [];
        foreach ($varian as $v) {
            if ($s = $v->model_sku ?: $v->item_sku) $sku[$s] = $s;
        }

        return array_values($sku);
    }

    /** @param string[] $sku */
    public function ringkas(array $sku): string
    {
        $sisa = count($sku) - self::MAKS_SKU_TAMPIL;

        return implode(', ', array_slice($sku, 0, self::MAKS_SKU_TAMPIL)) . ($sisa > 0 ? " +{$sisa} lainnya" : '');
    }

    /**
     * SKU tiap kartu produk di daftar gelembung, sekali query untuk semua.
     *
     * @return array<int, string[]> [id pesan => SKU]
     */
    public function skuKartu(iterable $pesan): array
    {
        $kartu = [];
        foreach ($pesan as $m) {
            if ($this->kartuProduk($m->raw) && ($id = $this->itemId($m->raw))) {
                $kartu[$m->id] = [$id, (int) data_get($m->raw, 'content.model_id')];
            }
        }
        if (!$kartu) return [];

        $varian = ShopeeItem::whereIn('item_id', array_unique(array_column($kartu, 0)))->orderBy('model_id')
            ->get(['item_id', 'model_id', 'model_sku', 'item_sku'])->groupBy('item_id');

        return array_filter(array_map(function (array $k) use ($varian) {
            $semua = $varian->get($k[0], collect());
            $pilihan = $semua->firstWhere('model_id', $k[1]);

            return $this->daftarSku($pilihan ? [$pilihan] : $semua);
        }, $kartu));
    }

    public function kartuProduk(mixed $raw): bool
    {
        return in_array(data_get($raw, 'message_type'), self::JENIS_KARTU, true);
    }

    /** Kartu item memakai item_id, kartu variasi memakai product_id untuk hal yang sama. */
    private function itemId(mixed $raw): int
    {
        return (int) (data_get($raw, 'content.item_id') ?: data_get($raw, 'content.product_id'));
    }
}
