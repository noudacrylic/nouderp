<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Controllers\WahaWebhookController;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmWebhookEvent;

/**
 * Apakah jalur webhook masih hidup?
 *
 * Kegagalan jalur ini adalah satu-satunya kerusakan CRM yang TIDAK KELIHATAN:
 * mengirim tetap berhasil, layarnya tetap rapi, cuma tidak ada lagi yang
 * masuk — persis seperti hari sepi. Yang hilang bukan cuma centang, tapi juga
 * balasan pelanggan, dan kejadian yang gagal diantar tidak pernah dikirim
 * ulang. Jadi diamnya harus disuarakan.
 *
 * Dijawab dari data SENDIRI, bukan dengan menelepon vendor: panggilan itu
 * bertimeout 20 detik dan layar inbox tidak boleh menunggu selama itu hanya
 * untuk memasang satu pita peringatan.
 *
 * Cara bacanya: setiap pesan keluar SELALU memancing kabar balik (minimal
 * 'message.sent') dalam hitungan detik. Kalau pesan terakhir sudah lewat
 * tenggang dan sejak itu tidak ada kabar apa pun, jalurnya patah. Sinyal ini
 * juga menangkap sebab-sebab lain yang gejalanya sama — endpoint dimatikan
 * vendor, terowongan mati, URL berganti, tanda tangan tak cocok — bukan cuma
 * satu di antaranya.
 */
class WebhookHealthService
{
    /**
     * Kabar balik biasanya datang dalam hitungan detik. Sepuluh menit adalah
     * ruang longgar supaya antrean vendor yang sedang padat tidak dituduh
     * rusak.
     */
    private const TENGGANG_MENIT = 10;

    /** Status pesan yang benar-benar KELUAR — mode aman & gagal tak dihitung. */
    private const KELUAR_SUNGGUHAN = ['terkirim', 'delivered', 'read'];

    /**
     * @return array{sejak: ?\Illuminate\Support\Carbon, kirim_terakhir: \Illuminate\Support\Carbon}|null
     *         null berarti tidak ada yang perlu dikhawatirkan.
     */
    public function sepi(): ?array
    {
        /*
         * KIRIMAN LEWAT WAHA TIDAK DIHITUNG — dan tanpa ini seluruh pita
         * berubah jadi alarm palsu yang menyala terus.
         *
         * Cara baca fungsi ini bertumpu pada satu hal: setiap pesan keluar
         * memancing kabar balik lewat jalur yang sama. Kabar balik WAHA sudah
         * dikecualikan di bawah (alasannya di sana), jadi kalau kirimannya
         * TIDAK ikut dikecualikan, sisi timbangannya jadi pincang: satu
         * balasan lewat nomor utama membuktikan "ada yang dikirim", tak ada
         * kabar resmi yang menyusul, lalu jalur resmi divonis mati padahal ia
         * cuma sedang tidak dipakai.
         *
         * Sejak Tahap 7 hampir semua balasan berangkat lewat WAHA, jadi tanpa
         * pengecualian ini pitanya praktis menyala selamanya — dan pita yang
         * selalu menyala sama saja dengan tidak ada pita, justru pada
         * kerusakan yang satu-satunya gejalanya adalah pita ini.
         */
        $kirim = CrmMessage::keluar()
            ->whereIn('status', self::KELUAR_SUNGGUHAN)
            ->whereHas('conversation', fn ($q) => $q->where('channel', '!=', CrmConversation::KANAL_CERMIN))
            ->orderByDesc('id')
            ->first(['id', 'status', 'sent_at', 'conversation_id']);

        // Belum pernah mengirim apa pun: tidak ada yang bisa disimpulkan.
        if (! $kirim?->sent_at || $kirim->sent_at->gt(now()->subMinutes(self::TENGGANG_MENIT))) {
            return null;
        }

        /*
         * Baris cermin WAHA DIKECUALIKAN, dan ini bukan kerapian.
         *
         * Yang dibuktikan fungsi ini adalah "jalur resmi masih mengabari kita".
         * Cermin WAHA menulis ke tabel yang sama, tapi hidupnya sama sekali
         * tidak bergantung pada jalur resmi — jadi satu chat masuk lewat WAHA
         * akan menyatakan jalur resmi sehat padahal ia sedang mati total, dan
         * pita peringatannya tidak akan pernah menyala lagi.
         */
        $kabar = CrmWebhookEvent::query()
            ->where(fn ($q) => $q
                ->whereNull('event_type')
                ->orWhere('event_type', 'not like', WahaWebhookController::PREFIX . '%'))
            ->orderByDesc('id')
            ->first(['id', 'created_at'])?->created_at;

        // Ada kabar SETELAH kiriman terakhir = jalurnya hidup. Status yang tidak
        // beranjak setelah itu urusan lain (HP pelanggan mati, misalnya), bukan
        // webhook yang patah.
        if ($kabar && $kabar->greaterThanOrEqualTo($kirim->sent_at)) {
            return null;
        }

        return ['sejak' => $kabar, 'kirim_terakhir' => $kirim->sent_at];
    }
}
