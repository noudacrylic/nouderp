<?php

namespace App\Modules\Shipping\Services;

use Illuminate\Support\Facades\Http;

/**
 * Membuka link pendek Google Maps (maps.app.goo.gl, goo.gl/maps) jadi link
 * panjang yang memuat koordinat.
 *
 * Ada karena "Bagikan" di aplikasi Maps HP selalu menghasilkan link pendek,
 * dan menyalin koordinat mentah di HP nyaris mustahil. Tanpa ini pelanggan
 * yang sudah mengirim lokasinya tetap harus dimintai ulang dalam bentuk lain.
 *
 * Redirect diikuti satu per satu dan HANYA ke domain Google — alamat yang
 * ditempel bisa datang dari pelanggan, jadi server tidak boleh disuruh
 * mengunjungi tujuan sembarang (termasuk jaringan internal).
 */
class MapsLinkExpander
{
    private const MAKS_LOMPATAN = 5;

    public static function linkPendek(string $teks): bool
    {
        return (bool) preg_match('~^https?://(maps\.app\.goo\.gl|goo\.gl/maps|g\.co/kgs)/~i', trim($teks));
    }

    /** Link panjang hasil redirect, atau null bila gagal / keluar dari domain Google. */
    public function buka(string $url): ?string
    {
        $url = trim($url);

        for ($i = 0; $i < self::MAKS_LOMPATAN; $i++) {
            if (! self::domainGoogle($url)) {
                return null;
            }

            try {
                $res = Http::withoutRedirecting()->timeout(8)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get($url);
            } catch (\Throwable) {
                return null;
            }

            $lokasi = $res->header('Location');

            if (! $res->redirect() || $lokasi === '') {
                return $url;
            }

            $url = $lokasi;

            // Begitu koordinatnya sudah terbaca, lompatan berikutnya tak perlu.
            if (parse_lat_long($url)['latitude'] !== null) {
                return $url;
            }
        }

        return $url;
    }

    /**
     * Nama/alamat tempat yang tertulis di link (`/place/NAMA/` atau `?q=NAMA`).
     *
     * Dipakai saat linknya TIDAK memuat koordinat — link hasil mencari nama
     * tempat. Koordinat tempat itu hanya bisa didapat lewat API Google
     * berbayar, tapi alamat tertulisnya tetap berguna untuk kurir reguler.
     */
    public static function namaTempat(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $nama = preg_match('~/maps/place/([^/@?]+)~', $url, $m) ? $m[1] : null;

        if ($nama === null) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $nama = is_string($q['q'] ?? null) ? $q['q'] : null;
        } else {
            $nama = urldecode(str_replace('+', ' ', $nama));
        }

        $nama = trim((string) $nama);

        return $nama !== '' ? $nama : null;
    }

    private static function domainGoogle(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return (bool) preg_match('~(^|\.)(google\.[a-z.]+|goo\.gl|g\.co)$~', $host)
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
