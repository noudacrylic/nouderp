<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmConversation;
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
