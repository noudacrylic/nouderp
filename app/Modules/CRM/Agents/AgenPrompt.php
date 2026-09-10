<?php

namespace App\Modules\CRM\Agents;

use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmAgentKnowledge;
use App\Modules\CRM\Models\CrmConversation;

/**
 * Menyusun apa yang dibaca agen sebelum menjawab.
 *
 * Pembagiannya bukan soal kerapian melainkan CACHE. Prompt caching mencocokkan
 * AWALAN: satu byte berubah di depan, seluruh yang di belakangnya bayar penuh
 * lagi. Karena itu isinya dipisah tegas menurut seberapa sering berubah:
 *
 *   system  : aturan tetap → persona → pengetahuan.  Berubah hanya saat
 *             seseorang menyunting agennya. Ini yang di-cache.
 *   messages: jam sekarang, nama pelanggan, sisa jendela.  Berubah tiap
 *             panggilan. Ini yang TIDAK boleh masuk system.
 *
 * AiAccountantService menaruh tanggal hari ini dan nama pengguna di dalam blok
 * system-nya, dan akibatnya cache-nya patah tiap ganti hari dan tiap ganti
 * orang — persis kesalahan yang dihindari di sini.
 */
class AgenPrompt
{
    /**
     * Blok system: stabil sepanjang versi pengetahuan tidak berubah.
     *
     * Urutannya disengaja. Aturan tetap berdiri paling depan supaya ia terbaca
     * sebagai kerangka, bukan sebagai catatan tambahan di kaki halaman — dan
     * teksnya sendiri menyatakan ia menang atas apa pun di bawahnya.
     */
    public static function system(CrmAgent $agen, ?CrmAgentKnowledge $pengetahuan): string
    {
        $bagian = [AturanTetap::teks()];

        if (filled($agen->persona)) {
            $bagian[] = "# Siapa kamu\n\n" . trim($agen->persona);
        }

        $bagian[] = filled($pengetahuan?->isi)
            ? "# Pengetahuan\n\n" . trim($pengetahuan->isi)
            : "# Pengetahuan\n\nBelum ada pengetahuan yang diunggah untuk agen ini. "
              . "Jawab hanya yang benar-benar kamu tahu dari hasil alat, dan lempar "
              . "sisanya ke tim.";

        return implode("\n\n---\n\n", $bagian);
    }

    /**
     * Fakta yang berubah tiap panggilan, ditempel di depan pesan pelanggan.
     *
     * Ditulis sebagai keterangan, bukan sebagai perintah — supaya agen tidak
     * salah membacanya sebagai pesan dari pelanggan. Jam disebut karena agen
     * bekerja 24 jam: yang menjawab pukul tiga pagi tidak boleh berpura-pura
     * ada orang di bengkel.
     */
    public static function keterangan(?CrmConversation $percakapan): string
    {
        $baris = [
            'Sekarang: ' . now()->translatedFormat('l, d M Y H:i'),
            'Jam operasional toko: ' . config('crm.store_hours_text', 'Senin–Sabtu 08.00–16.00'),
        ];

        if ($percakapan) {
            $baris[] = 'Sapaan untuk pelanggan ini: ' . $percakapan->sapaan();
            $baris[] = $percakapan->customer_id
                ? 'Status: pelanggan lama yang sudah terdaftar.'
                : 'Status: belum terdaftar sebagai pelanggan (lead).';
        }

        $jam = (int) now()->format('H');
        $buka  = (int) config('crm.store_open_hour', 8);
        $tutup = (int) config('crm.store_close_hour', 16);

        if ($jam < $buka || $jam >= $tutup) {
            $baris[] = 'Di luar jam operasional — tim baru bisa menindaklanjuti pada jam kerja berikutnya. '
                . 'Jangan berpura-pura ada orang di bengkel sekarang.';
        }

        return "[Keterangan untukmu, bukan dari pelanggan]\n" . implode("\n", $baris);
    }
}
