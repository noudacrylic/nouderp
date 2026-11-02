<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmConversation;
use App\Modules\Marketplace\Shopee\Models\ShopeeOrder;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use App\Modules\Marketplace\Shopee\Services\ShopeePesananService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chat mana yang dimiliki sebuah pesanan — penentu tombol chat di kartu Desain (Meja Kerja) dan
 * kartu Pemrosesan Pesanan. Dihitung per kelompok supaya daftar panjang tidak menembak query
 * per kartu. Pesanan Shopee dikenali dari customer_po_number 'SP-<order_sn>'.
 */
class ChatPesananService
{
    /** Teks pembuka bawaan "Mulai Chat Shopee" dari luar Desain; admin bisa mengubah sebelum kirim. */
    public const SAPAAN = 'Halo kak, terima kasih sudah order di Noud Acrylic 🙏 Kami ingin mengonfirmasi pesanannya ya kak.';

    /** Tombol "Cek pesanan": paling sering sekali semenit untuk semua pengguna. */
    private const JEDA_CEK_DETIK = 60;

    /** Cukup menutup celah sejak tarikan terjadwal terakhir (tiap 15 menit), dengan cadangan. */
    private const JENDELA_CEK_MENIT = 30;

    /**
     * SO milik pembeli chat Shopee ini, lewat ID pembeli di salinan pesanan Shopee. Hanya membaca:
     * pesanan yang tak tertaut otomatis (sudah selesai, sudah dilepas CS, tertaut chat WA) tetap
     * tampil dengan tombol "tautkan". Tautan & labelnya diurus LabelPesananService.
     *
     * @return int[]
     */
    public function idPesananPembeliShopee(CrmConversation $chat): array
    {
        if (!$chat->shopee() || !ctype_digit((string) $chat->contact_key)) return [];

        $sn = DB::table('shopee_orders')
            ->where('shop_id', (int) $chat->business_number_id)
            ->where('buyer_user_id', (int) $chat->contact_key)
            ->pluck('order_sn');
        if ($sn->isEmpty()) return [];

        return DB::table('sales_orders')
            ->whereIn('customer_po_number', $sn->map(fn ($s) => 'SP-' . $s)->all())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Tombol "Cek pesanan": tarik pesanan terbaru dari Shopee sekarang, tanpa menunggu jadwal
     * shopee:tarik-bayangan (15 menit). Jeda bersama semua pengguna menjaga API tetap tenang:
     * klik dalam jeda hanya membaca ulang data yang ada.
     *
     * @return array{error:?string, belum_so:array<int, array{order_sn:string, belum_bayar:bool}>}
     */
    public function cekPesananShopee(CrmConversation $chat): array
    {
        $shop = ShopeeShop::aktif();
        if (!app(ShopeeClient::class)->siap() || !$shop || $shop->perluHubungkanUlang()) {
            return ['error' => 'Toko Shopee belum terhubung di Pengaturan › Integrasi › Shopee.', 'belum_so' => []];
        }

        if (Cache::add('shopee-cek-pesanan', 1, self::JEDA_CEK_DETIK)) {
            try {
                app(ShopeePesananService::class)->tarik($shop, now()->subMinutes(self::JENDELA_CEK_MENIT), now());
            } catch (ShopeeException $e) {
                Cache::forget('shopee-cek-pesanan');

                return ['error' => 'Gagal mengambil pesanan dari Shopee: ' . $e->getMessage(), 'belum_so' => []];
            }
        }

        return ['error' => null, 'belum_so' => $this->pesananBelumJadiSo($chat)];
    }

    /** Pesanan pembeli yang sudah ada di Shopee tapi SO-nya belum dibuat sinkron Jubelio. */
    private function pesananBelumJadiSo(CrmConversation $chat): array
    {
        if (!$chat->shopee() || !ctype_digit((string) $chat->contact_key)) return [];

        $pesanan = ShopeeOrder::where('shop_id', (int) $chat->business_number_id)
            ->where('buyer_user_id', (int) $chat->contact_key)
            ->where('dibuat_shopee_at', '>=', now()->subDays(30))
            ->get()->reject(fn (ShopeeOrder $o) => $o->batal());
        if ($pesanan->isEmpty()) return [];

        $adaSo = DB::table('sales_orders')
            ->whereIn('customer_po_number', $pesanan->map(fn ($o) => 'SP-' . $o->order_sn)->all())
            ->pluck('customer_po_number')->flip();

        return $pesanan->reject(fn ($o) => $adaSo->has('SP-' . $o->order_sn))
            ->map(fn ($o) => ['order_sn' => $o->order_sn, 'belum_bayar' => $o->status === 'UNPAID'])
            ->values()->all();
    }

    /**
     * @param  array<int, ?string>  $noMarketplace  [sales_order_id => customer_po_number]
     * @return array<int, array{tertaut:bool, wa:?int, shopee:?int, order_sn:?string, bisa_sapa:bool}>
     */
    public function untuk(array $noMarketplace): array
    {
        $hasil = [];
        foreach ($noMarketplace as $soId => $no) {
            $hasil[$soId] = [
                'tertaut'   => false,
                'wa'        => null,
                'shopee'    => null,
                'order_sn'  => str_starts_with((string) $no, 'SP-') ? substr($no, 3) : null,
                'bisa_sapa' => false,
            ];
        }
        if (!$hasil) return [];

        // Tautan pesanan↔chat. Satu SO paling banyak satu tautan (unique sales_order_id).
        DB::table('crm_pesanan_chat as pc')->join('crm_conversations as c', 'c.id', '=', 'pc.conversation_id')
            ->whereIn('pc.sales_order_id', array_keys($hasil))
            ->get(['pc.sales_order_id', 'c.id', 'c.channel'])
            ->each(function ($t) use (&$hasil) {
                $hasil[$t->sales_order_id]['tertaut'] = true;
                if (str_starts_with((string) $t->channel, 'whatsapp')) $hasil[$t->sales_order_id]['wa'] = (int) $t->id;
                if ($t->channel === CrmConversation::KANAL_SHOPEE) $hasil[$t->sales_order_id]['shopee'] = (int) $t->id;
            });

        // Belum tertaut: chat Shopee mana pun dengan pembeli pesanan itu, lewat ID pembeli
        // di salinan pesanan Shopee. ID pembeli itu juga syarat toko bisa menyapa duluan.
        $sn = array_filter(array_column($hasil, 'order_sn'));
        if (!$sn) return $hasil;

        $pembeli = DB::table('shopee_orders')->whereIn('order_sn', $sn)->whereNotNull('buyer_user_id')
            ->get(['order_sn', 'shop_id', 'buyer_user_id'])->keyBy('order_sn');
        $chats = $pembeli->isEmpty() ? collect() : DB::table('shopee_chats')
            ->whereIn('to_id', $pembeli->pluck('buyer_user_id')->unique()->all())
            ->whereNotNull('crm_conversation_id')
            ->orderByDesc('last_message_at')
            ->get(['shop_id', 'to_id', 'crm_conversation_id'])
            ->unique(fn ($c) => $c->shop_id . ':' . $c->to_id)
            ->keyBy(fn ($c) => $c->shop_id . ':' . $c->to_id);

        foreach ($hasil as $soId => $h) {
            $o = $h['order_sn'] ? $pembeli->get($h['order_sn']) : null;
            if (!$o) continue;
            $hasil[$soId]['bisa_sapa'] = true;
            $hasil[$soId]['shopee'] ??= ($c = $chats->get($o->shop_id . ':' . $o->buyer_user_id)) ? (int) $c->crm_conversation_id : null;
        }

        return $hasil;
    }
}
