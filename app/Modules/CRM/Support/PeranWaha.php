<?php

namespace App\Modules\CRM\Support;

/**
 * Katalog PERAN sesi WAHA. Satu container WAHA, beberapa nomor WhatsApp.
 *
 * Kenapa peran, bukan sekadar "sesi 1 / sesi 2": yang membedakan kedua nomor
 * bukan urutannya melainkan APA YANG RUSAK kalau sesinya putus. Nomor
 * notifikasi mati = kabar pesanan tertahan di antrean lalu dialihkan ke
 * template berbayar. Nomor utama mati = percakapan pelanggan berhenti terpantau
 * ERP, dan tak ada jalur berbayar yang bisa menggantikannya. Dua akibat yang
 * berbeda menuntut peringatan yang berbeda pula — peringatan yang menyebut
 * akibat keliru akan membuat orang mengerjakan pemulihan yang keliru.
 *
 * ⚠️ NOMORNYA WAJIB BERBEDA. Nomor notifikasi yang mengirim duluan adalah
 * jalur yang paling mungkin diblokir; nomor utama adalah identitas toko yang
 * tak tergantikan. Menyatukan keduanya membuat risiko yang paling besar
 * ditanggung oleh aset yang paling mahal.
 */
final class PeranWaha
{
    /** Nomor yang mengirim kabar pesanan (kirim duluan — paling berisiko). */
    public const NOTIFIKASI = 'notifikasi';

    /** Nomor utama toko: tempat pelanggan chat, dibalas manusia. */
    public const UTAMA = 'utama';

    public const SEMUA = [self::UTAMA, self::NOTIFIKASI];

    public static function sah(?string $peran): bool
    {
        return $peran !== null && in_array($peran, self::SEMUA, true);
    }

    public static function label(string $peran): string
    {
        return match ($peran) {
            self::UTAMA      => 'Nomor Utama (chat pelanggan)',
            self::NOTIFIKASI => 'Nomor Notifikasi (kabar pesanan)',
            default          => $peran,
        };
    }

    public static function penjelasan(string $peran): string
    {
        return match ($peran) {
            self::UTAMA => 'Nomor yang dipajang ke pelanggan dan dibalas manusia. WAHA menempel '
                . 'sebagai perangkat tertaut, persis seperti WhatsApp Web — HP tetap bisa dipakai CS '
                . 'seperti biasa. Hanya membalas, tidak pernah untuk blast.',
            self::NOTIFIKASI => 'Nomor terpisah yang mengirim kabar pesanan (pembayaran diterima, '
                . 'siap diambil, resi). Karena ia yang mengirim duluan, dialah yang paling mungkin '
                . 'diblokir — dan karena itu pula ia dipisah dari nomor utama.',
            default => '',
        };
    }

    /**
     * Apa yang rusak kalau sesi peran ini putus. Dipakai peringatan Telegram:
     * "sesi mati" tanpa akibatnya tidak memberi tahu siapa pun apa yang harus
     * dikerjakan, dan akibat yang disebut keliru justru lebih buruk lagi.
     */
    public static function akibatPutus(string $peran): string
    {
        return match ($peran) {
            self::UTAMA => 'Percakapan nomor utama berhenti terpantau ERP. Pelanggan tetap bisa '
                . 'chat dan CS tetap bisa membalas dari HP, tapi ERP tidak melihatnya.',
            self::NOTIFIKASI => 'Notifikasi pesanan ditahan di antrean, dan setelah '
                . (int) config('crm.notifikasi.tahan_maks_jam', 3)
                . ' jam dialihkan ke template berbayar.',
            default => '',
        };
    }

    /** Nama sesi bawaan di WAHA bila belum diisi sendiri di layar Pengaturan. */
    public static function bawaanSesi(string $peran): string
    {
        return (string) config('crm.waha.sesi.' . $peran, $peran);
    }
}
