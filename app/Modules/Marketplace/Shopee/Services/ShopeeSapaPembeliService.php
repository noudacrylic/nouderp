<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Modules\CRM\Models\CrmMessage;
use App\Modules\Marketplace\Shopee\Models\ShopeeChat;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Toko menyapa pembeli duluan lewat chat Shopee: kartu pesanan + teks pembuka, lalu
 * percakapannya langsung disalin ke CRM. Pembeli tidak perlu pernah chat — penerimanya
 * `shopee_orders.buyer_user_id` (teruji 31 Okt 2026: Shopee membuat percakapan baru).
 *
 * Dipakai tombol "Mulai Chat Shopee" di kartu Desain; nanti juga karyawan virtual CS Shopee.
 */
class ShopeeSapaPembeliService
{
    public function __construct(private ShopeeClient $client, private ShopeeChatService $chat) {}

    /** Percakapan CRM pembeli pesanan ini (chat Shopee mana pun dengan pembelinya), bila sudah ada. */
    public function percakapanCrm(string $orderSn): ?int
    {
        $o = DB::table('shopee_orders')->where('order_sn', $orderSn)->whereNotNull('buyer_user_id')->first(['shop_id', 'buyer_user_id']);
        if (!$o) return null;

        $id = ShopeeChat::where('shop_id', $o->shop_id)->where('to_id', $o->buyer_user_id)
            ->whereNotNull('crm_conversation_id')->orderByDesc('last_message_at')->value('crm_conversation_id');

        return $id ? (int) $id : null;
    }

    /**
     * Kirim kartu pesanan + teks ke pembeli, lalu tarik percakapannya ke CRM. Bila chat dengan
     * pembeli itu sudah ada di CRM, tidak mengirim apa pun (cegah sapaan dobel).
     *
     * @return array{conversation_id:?int, terkirim:bool, error:?string}
     */
    public function sapa(string $orderSn, string $teks, ?int $userId = null): array
    {
        $teks = trim($teks);
        if ($teks === '') return $this->gagal('Pesan pembuka kosong.');

        $o = DB::table('shopee_orders')->where('order_sn', $orderSn)->first(['shop_id', 'buyer_user_id']);
        if (!$o) return $this->gagal("Pesanan $orderSn belum ada di salinan pesanan Shopee — tunggu tarikan berikutnya (tiap 15 menit).");
        if (!$o->buyer_user_id) return $this->gagal("ID pembeli pesanan $orderSn belum diketahui.");

        $shop = ShopeeShop::where('env', $this->client->env())->where('shop_id', $o->shop_id)->first();
        if (!$shop || $shop->perluHubungkanUlang()) return $this->gagal('Toko Shopee belum terhubung di Pengaturan › Integrasi › Shopee.');

        $kunci = Cache::lock('shopee-sapa:' . $orderSn, 60);
        if (!$kunci->get()) return $this->gagal('Sapaan pesanan ini sedang dikirim admin lain.');

        try {
            if ($ada = $this->percakapanCrm($orderSn)) {
                return ['conversation_id' => $ada, 'terkirim' => false, 'error' => null];
            }

            $ids = [];
            try {
                foreach ([['order', ['order_sn' => $orderSn]], ['text', ['text' => $teks]]] as [$jenis, $isi]) {
                    $r = $this->client->panggil($shop, 'POST', '/api/v2/sellerchat/send_message', [
                        'to_id' => (int) $o->buyer_user_id, 'message_type' => $jenis, 'content' => $isi,
                    ]);
                    if (!empty($r['message_id'])) $ids[] = 'shopee:' . $r['message_id'];
                }
            } catch (ShopeeException $e) {
                return $this->gagal('Shopee menolak pesan: ' . $e->getMessage());
            }

            // Salin sekarang, jangan menunggu sinkron tiap menit — admin langsung dibawa ke chatnya.
            try {
                $this->chat->tarikBaru($shop, 1);
            } catch (ShopeeException $e) {
                report($e);
            }

            $cid = $this->percakapanCrm($orderSn);
            if ($cid && $userId && $ids) {
                CrmMessage::whereIn('provider_message_id', $ids)->whereNull('sent_by_user_id')->update(['sent_by_user_id' => $userId]);
            }

            return ['conversation_id' => $cid, 'terkirim' => true, 'error' => null];
        } finally {
            $kunci->release();
        }
    }

    private function gagal(string $pesan): array
    {
        return ['conversation_id' => null, 'terkirim' => false, 'error' => $pesan];
    }
}
