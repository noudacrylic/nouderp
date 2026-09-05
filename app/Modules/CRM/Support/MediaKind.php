<?php

namespace App\Modules\CRM\Support;

use Illuminate\Support\Str;

/**
 * Menentukan JENIS media WhatsApp dari mime, beserta batas ukurannya.
 *
 * WhatsApp tidak mengenal "lampiran" sebagai satu benda: gambar, video, audio,
 * dan dokumen adalah empat jenis pesan berbeda dengan batas ukuran yang jauh
 * berbeda pula. Mengirim PDF sebagai 'image' ditolak Meta; mengirim gambar 20 MB
 * sebagai 'document' memang lolos, tapi sampai ke pelanggan sebagai berkas yang
 * harus diunduh, bukan gambar yang langsung terlihat.
 *
 * Batasnya sengaja dituliskan di sini, bukan disebar di validasi: kalau angkanya
 * berbeda antara layar dan adapter, admin akan ditolak Meta setelah berkasnya
 * terlanjur terunggah — kegagalan yang paling melelahkan karena datang belakangan.
 */
class MediaKind
{
    /** Batas resmi WhatsApp Cloud API (byte). */
    private const BATAS = [
        'image'    => 5 * 1024 * 1024,
        'video'    => 16 * 1024 * 1024,
        'audio'    => 16 * 1024 * 1024,
        'document' => 100 * 1024 * 1024,
    ];

    /**
     * Sticker sengaja TIDAK didukung: syaratnya webp dengan batas 100 KB dan
     * ukuran kanvas tertentu, dan berkas yang tak memenuhi ditolak Meta tanpa
     * keterangan yang berguna. Dikirim sebagai gambar biasa jauh lebih jujur.
     *
     * @return array{type:string, max_bytes:int}
     */
    public static function for(?string $mime): array
    {
        $mime = Str::lower(Str::before((string) $mime, ';'));

        // 'image/webp' & 'image/gif' ikut jalur dokumen: WhatsApp menolak
        // keduanya sebagai gambar biasa (webp = sticker, gif tak didukung).
        $type = match (true) {
            in_array($mime, ['image/jpeg', 'image/jpg', 'image/png'], true) => 'image',
            str_starts_with($mime, 'video/')                                => 'video',
            str_starts_with($mime, 'audio/')                                => 'audio',
            default                                                          => 'document',
        };

        return ['type' => $type, 'max_bytes' => self::BATAS[$type]];
    }

    /** Batas terbesar yang mungkin — dipakai penjaga awal sebelum jenisnya diperiksa. */
    public static function batasTertinggi(): int
    {
        return max(self::BATAS);
    }

    /** Keterangan singkat untuk pesan galat yang bisa dibaca admin. */
    public static function keterangan(string $type): string
    {
        return match ($type) {
            'image'    => 'gambar (maks 5 MB)',
            'video'    => 'video (maks 16 MB)',
            'audio'    => 'audio (maks 16 MB)',
            default    => 'dokumen (maks 100 MB)',
        };
    }
}
