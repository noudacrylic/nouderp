<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmLabel;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\OrderProgressService;
use Illuminate\Support\Facades\DB;

/**
 * CRM Tahap 3 — label chat mengikuti pesanan yang tertaut (disepakati 8 Okt 2026).
 *
 * Label DIHITUNG dari keadaan pesanan saat itu, bukan didorong per peristiwa.
 * Peristiwanya banyak dan tersebar (bayar, OP, finalisasi, surat jalan,
 * status marketplace), bisa datang terlambat atau dibatalkan lewat void —
 * menghitung ulang dari keadaan membuat hasilnya selalu sama, dari jalan mana
 * pun ia dipicu. Tahapannya memakai OrderProgressService, sumber yang sama
 * dengan halaman lacak pesanan pembeli.
 *
 * Label hanya digeser PERISTIWA PESANAN, bukan pesan masuk — sejalan dengan
 * keputusan lama "label manual jangan ditimpa alur pesan". Label yang diganti
 * tangan bertahan sampai peristiwa pesanan berikutnya.
 */
class LabelPesananService
{
    // Label kerja yang dibuat lewat layar di server (bukan migrasi).
    public const TANYA_HARGA = 'tanya_harga';
    public const DESAIN      = 'desain';
    public const PRODUKSI    = 'produksi';
    public const PRINT       = 'cetak';

    // Label sistem dari migrasi Tahap 1–2.
    public const MENUNGGU_PEMBAYARAN = 'menunggu_pembayaran';
    public const MENUNGGU_DIKIRIM    = 'menunggu_dikirim';
    public const MENUNGGU_PELUNASAN  = 'menunggu_pelunasan';
    public const SELESAI             = CrmConversation::LABEL_SELESAI;

    /** Status OP yang berarti produksi SEDANG dikerjakan (belum final). */
    private const OP_BERJALAN = ['in_progress', 'partial', 'completed'];

    public function __construct(private OrderProgressService $progress)
    {
    }

    public function tautkan(CrmConversation $chat, SalesOrder $so, ?int $userId = null): void
    {
        DB::table('crm_pesanan_chat')->upsert([[
            'conversation_id' => $chat->id,
            'sales_order_id'  => $so->id,
            'user_id'         => $userId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]], ['sales_order_id'], ['conversation_id', 'user_id', 'updated_at']);

        $this->perbaruiUntukSo($so->id);
    }

    /**
     * Tautkan SO ke chat pelanggannya tanpa campur tangan — untuk pesanan yang
     * dibuat di LUAR chat (modul Sales, kasir), mis. orang pesan lewat WA lalu
     * SO-nya diketik di Sales. Tanpa tautan, labelnya tak pernah bergerak.
     *
     * Hanya bila jawabannya pasti: pelanggan punya TEPAT SATU chat. Pelanggan
     * marketplace & "Umum" (walk-in kasir) tidak pernah — mereka bukan orang
     * yang sedang chat. Yang ragu dibiarkan, tetap bisa ditautkan dari panel
     * Pesanan.
     *
     * @return int|null id chat yang ditautkan
     */
    public function tautkanOtomatis(SalesOrder $so): ?int
    {
        $chatId = $this->chatUntukTautanOtomatis($so);

        if (! $chatId) {
            return null;
        }

        DB::table('crm_pesanan_chat')->insertOrIgnore([
            'conversation_id' => $chatId,
            'sales_order_id'  => $so->id,
            'user_id'         => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $chatId;
    }

    /** Chat tujuan tautan otomatis, atau null bila tidak pasti / sudah tertaut. */
    public function chatUntukTautanOtomatis(SalesOrder $so): ?int
    {
        if (! $so->customer_id || in_array($so->status, ['void', 'cancelled'], true)
            || DB::table('crm_pesanan_chat')->where('sales_order_id', $so->id)->exists()) {
            return null;
        }

        $pelanggan = DB::table('customers')->where('id', $so->customer_id)->first(['is_marketplace', 'code']);

        if (! $pelanggan || $pelanggan->is_marketplace || $pelanggan->code === 'UMUM') {
            return null;
        }

        $chatIds = CrmConversation::where('customer_id', $so->customer_id)->limit(2)->pluck('id');

        return $chatIds->count() === 1 ? (int) $chatIds->first() : null;
    }

    public function lepas(CrmConversation $chat, SalesOrder $so): void
    {
        DB::table('crm_pesanan_chat')
            ->where('conversation_id', $chat->id)
            ->where('sales_order_id', $so->id)
            ->delete();
    }

    /** @return int[] id SO yang tertaut ke chat ini, terbaru dulu */
    public function idTertaut(CrmConversation $chat): array
    {
        return DB::table('crm_pesanan_chat')
            ->where('conversation_id', $chat->id)
            ->orderByDesc('sales_order_id')
            ->pluck('sales_order_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Dipanggil observer setiap kali SO, pembayaran, OP, surat jalan, atau
     * status marketplace berubah. TIDAK PERNAH melempar: label chat adalah
     * pelengkap, dan kegagalannya tidak boleh menggagalkan posting pembayaran
     * atau sinkron marketplace yang memicunya.
     */
    public function perbaruiUntukSo(?int $soId): void
    {
        if (! $soId) {
            return;
        }

        try {
            $chatId = DB::table('crm_pesanan_chat')->where('sales_order_id', $soId)->value('conversation_id');

            if (! $chatId) {
                return;
            }

            // Hanya pesanan TERBARU di chat itu yang menggerakkan labelnya.
            $terbaru = (int) DB::table('crm_pesanan_chat')->where('conversation_id', $chatId)->max('sales_order_id');

            if ($terbaru !== (int) $soId) {
                return;
            }

            $so   = SalesOrder::find($soId);
            $chat = CrmConversation::find($chatId);
            $kode = $so ? $this->labelUntuk($so) : null;

            // Distributor tetap berlabel Distributor; kode yang belum dibuat di
            // layar Label dilewati daripada memasang tulisan mentah.
            if (! $chat || ! $kode || $chat->is_distributor || $chat->queue_state === $kode
                || ! CrmLabel::where('kode', $kode)->exists()) {
                return;
            }

            $chat->forceFill(['queue_state' => $kode])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Label untuk satu pesanan, atau null bila label tidak perlu diubah
     * (pesanan batal/void).
     */
    public function labelUntuk(SalesOrder $so): ?string
    {
        if (in_array($so->status, ['void', 'cancelled'], true)) {
            return null;
        }

        $mp = JubelioOrderLink::where('sales_order_id', $so->id)->first();

        if ($mp) {
            return $this->labelMarketplace($so, $mp);
        }

        if ($so->status === 'draft') {
            return self::MENUNGGU_PEMBAYARAN;
        }

        $p = $this->progress->for($so);

        if (! empty($p['cancelled'])) {
            return null;
        }

        /*
         * "Diserahkan ke kurir / diserahkan ke pelanggan = Selesai" (disepakati):
         * tahap `kirim` (resi terbit / dalam perjalanan) sudah dihitung selesai,
         * bukan menunggu kabar sampai — sesudah itu tak ada lagi yang bisa kita
         * kerjakan, dan chatnya tutup sendiri 3 hari kemudian.
         */
        if (in_array($p['current'], ['kirim', 'selesai'], true)) {
            return self::SELESAI;
        }

        if ($p['current'] === 'bayar') {
            return self::MENUNGGU_PEMBAYARAN;
        }

        if (! $this->adaItemCustom($so)) {
            return self::MENUNGGU_DIKIRIM;
        }

        $tahap = $this->tahapProduksi($so);

        if ($tahap !== 'final') {
            return $tahap === 'berjalan' ? self::PRODUKSI : self::DESAIN;
        }

        return ($p['payment']['state'] ?? null) === 'dp' ? self::MENUNGGU_PELUNASAN : self::MENUNGGU_DIKIRIM;
    }

    /**
     * Pesanan marketplace — sudah lunas di marketplace, jadi tahap pembayaran &
     * pelunasan dilewati (disepakati 8 Okt 2026).
     *
     * Alur custom dikenali dari ADANYA OP, bukan hanya tanda produk custom: di
     * data server 154 OP terbit dari pesanan marketplace, padahal hanya 4
     * pesanan yang SKU-nya bertanda made-to-order — order custom via
     * marketplace umumnya tercatat dengan SKU biasa. Tanpa OP & tanpa produk
     * custom = pesanan yang butuh desain/print (Print).
     */
    private function labelMarketplace(SalesOrder $so, JubelioOrderLink $mp): string
    {
        if ($mp->shipped_at || $mp->mp_completed_at || $mp->wms_completed_at) {
            return self::SELESAI;
        }

        $tahap = $this->tahapProduksi($so);

        return match (true) {
            $tahap === 'final'        => self::MENUNGGU_DIKIRIM,
            $tahap === 'berjalan'     => self::PRODUKSI,
            $tahap === 'menunggu',
            $this->adaItemCustom($so) => self::DESAIN,
            default                   => self::PRINT,
        };
    }

    /** Satu item custom saja sudah menjadikan seluruh pesanan alur custom. */
    private function adaItemCustom(SalesOrder $so): bool
    {
        return $so->items()->whereHas('product', fn ($q) => $q->madeToOrder())->exists();
    }

    /**
     * Keadaan order produksi pesanan ini: 'tidak_ada' | 'menunggu' |
     * 'berjalan' | 'final'.
     *
     * "Menunggu" (OP ada tapi belum dikerjakan) dihitung tahap Desain: OP
     * preorder lahir otomatis begitu DP diterima, jadi "OP sudah terbit" tidak
     * bisa jadi penanda produksi — tahap Desain akan selalu terlewati. Yang
     * dipakai: OP mulai dikerjakan (disepakati 8 Okt 2026).
     */
    private function tahapProduksi(SalesOrder $so): string
    {
        $op = $so->productionOrders()->where('status', '!=', 'cancelled')->pluck('status');

        return match (true) {
            $op->isEmpty() => 'tidak_ada',
            $op->every(fn ($s) => $s === 'finalized') => 'final',
            $op->contains(fn ($s) => in_array($s, [...self::OP_BERJALAN, 'finalized'], true)) => 'berjalan',
            default => 'menunggu',
        };
    }
}
