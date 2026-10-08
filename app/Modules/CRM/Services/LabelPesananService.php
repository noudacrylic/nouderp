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

        // Pesanan marketplace (sudah dibayar di sana, butuh desain/print):
        // Print sampai dikirim, lalu Selesai.
        $mp = JubelioOrderLink::where('sales_order_id', $so->id)->first();

        if ($mp) {
            return ($mp->shipped_at || $mp->mp_completed_at || $mp->wms_completed_at) ? self::SELESAI : self::PRINT;
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

        // Satu item custom saja sudah menjadikan seluruh pesanan alur custom.
        $custom = $so->items()->whereHas('product', fn ($q) => $q->madeToOrder())->exists();

        if (! $custom) {
            return self::MENUNGGU_DIKIRIM;
        }

        $op = $so->productionOrders()->where('status', '!=', 'cancelled')->pluck('status');

        /*
         * Desain = sudah dibayar, produksi BELUM dikerjakan. OP preorder lahir
         * otomatis begitu DP diterima, jadi "OP sudah ada" tidak bisa jadi
         * penanda — tahap Desain akan selalu terlewati. Yang dipakai: OP mulai
         * dikerjakan.
         */
        if ($op->isEmpty() || $op->every(fn ($s) => ! in_array($s, [...self::OP_BERJALAN, 'finalized'], true))) {
            return self::DESAIN;
        }

        if (! $op->every(fn ($s) => $s === 'finalized')) {
            return self::PRODUKSI;
        }

        return ($p['payment']['state'] ?? null) === 'dp' ? self::MENUNGGU_PELUNASAN : self::MENUNGGU_DIKIRIM;
    }
}
