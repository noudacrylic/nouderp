<?php

namespace App\Modules\CRM\Support;

use Illuminate\Support\HtmlString;

/**
 * Menyiapkan isi pesan untuk digambar di gelembung thread.
 *
 * Ada karena thread ERP dipakai untuk MEMERIKSA apa yang sudah terkirim, dan
 * pemeriksaan itu setengah jalan kalau tautannya tidak bisa dibuka: admin
 * harus menyorot, menyalin, lalu menempel ke tab baru hanya untuk memastikan
 * tautan yang barusan ia kirim benar-benar menuju halaman yang benar. Di HP
 * pelanggan tautannya sudah bisa diklik; layar kita jangan lebih miskin.
 */
class TeksPesan
{
    /**
     * Escape dulu, BARU tautkan — urutannya tidak boleh dibalik.
     *
     * Isi pesan bisa datang dari pelanggan, jadi ia teks asing sepenuhnya.
     * Menyulam <a> ke teks mentah lalu menyerahkannya sebagai HTML berarti
     * siapa pun yang mengirimi kita chat bisa menitipkan markup ke layar
     * admin. Dengan escape di depan, satu-satunya HTML yang lolos adalah
     * tautan yang kita rangkai sendiri.
     */
    public static function tautkan(?string $teks): HtmlString
    {
        $aman = e((string) $teks);

        $hasil = preg_replace_callback(
            '~https?://[^\s<]+~i',
            function (array $m) {
                $url = $m[0];

                /*
                 * Tanda baca di ekor kalimat ("… lihat di https://a.com/b.")
                 * bukan bagian alamat. Dilepas dari tautannya lalu ditulis
                 * kembali sebagai teks biasa; kalau ikut terbawa, tautannya
                 * membuka 404 padahal yang dikirim ke pelanggan benar.
                 */
                $ekor = '';
                while ($url !== '' && str_contains('.,;:!?)]}\'"', substr($url, -1))) {
                    $ekor = substr($url, -1) . $ekor;
                    $url  = substr($url, 0, -1);
                }

                // Entitas HTML hasil escape ikut terpotong kalau titik komanya
                // dipangkas mentah-mentah (&amp; → &amp). Dikembalikan utuh.
                if (preg_match('~&[a-z]+$~i', $url)) {
                    $url .= ';';
                    $ekor = substr($ekor, 1);
                }

                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer"'
                     . ' class="text-sky-700 underline break-all hover:text-sky-900">' . $url . '</a>' . $ekor;
            },
            $aman
        );

        return new HtmlString($hasil ?? $aman);
    }
}
