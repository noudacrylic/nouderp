<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;

/**
 * "Fokus" chat Shopee seperti di Seller Centre: kartu produk terakhir yang dikirim pembeli (chat
 * dari halaman produk) → listing + SKU variannya. Kartu lebih tua dari 7 hari dianggap sudah bukan
 * topik. Kartu hanya membawa item_id, jadi varian yang dimaksud pembeli tidak diketahui: bila
 * produknya bervarian, semua SKU-nya yang ditunjukkan.
 */
class ProdukDibahasService
{
    /** SKU yang ditulis di layar; sisanya jadi "+n lainnya" dan tetap terbaca di title. */
    private const MAKS_SKU_TAMPIL = 4;

    /** @return array{item_id:int, nama:string, sent_at:\Illuminate\Support\Carbon, varian:\Illuminate\Support\Collection}|null */
    public function untukChat(CrmConversation $chat): ?array
    {
        if (!$chat->shopee()) return null;

        $kartu = CrmMessage::where('conversation_id', $chat->id)
            ->where('direction', CrmMessage::MASUK)
            ->where('raw->message_type', 'item')
            ->where('sent_at', '>=', now()->subDays(7))
            ->latest('sent_at')->latest('id')->first();
        $itemId = (int) data_get($kartu?->raw, 'content.item_id');
        if (!$itemId) return null;

        $varian = ShopeeItem::where('shop_id', (int) $chat->business_number_id)->where('item_id', $itemId)
            ->orderBy('model_id')->get(['nama', 'nama_model', 'model_sku', 'item_sku', 'stok']);
        if ($varian->isEmpty()) return null;

        return ['item_id' => $itemId, 'nama' => (string) $varian->first()->nama, 'sent_at' => $kartu->sent_at, 'varian' => $varian];
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
        $item = [];
        foreach ($pesan as $m) {
            if (data_get($m->raw, 'message_type') === 'item' && ($id = (int) data_get($m->raw, 'content.item_id'))) {
                $item[$m->id] = $id;
            }
        }
        if (!$item) return [];

        $varian = ShopeeItem::whereIn('item_id', array_unique($item))->orderBy('model_id')
            ->get(['item_id', 'model_sku', 'item_sku'])->groupBy('item_id');

        return array_filter(array_map(fn ($id) => $this->daftarSku($varian->get($id, [])), $item));
    }
}
