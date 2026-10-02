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
        return (bool) preg_match('~^https?://(maps\.app\.goo\.gl|goo\.gl/maps|g\.co/kgs|share\.google)/~i', trim($teks));
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

    /**
     * Titik tempat dari link halaman tempat Google (`?kgmid=/g/...&q=NAMA`).
     *
     * Ada karena "Bagikan" Google versi baru (share.google, g.co/kgs) berujung
     * di halaman Google Search, bukan Maps — tak ada koordinat di linknya.
     * Halaman Maps untuk nama + kgmid itu memuat daftar hasil yang menyimpan
     * titik persis tempatnya; diambil tanpa API key.
     *
     * Hanya dipercaya bila hasil yang memuat kgmid itu sendiri ditemukan —
     * nama tempat saja bisa kembar, dan titik yang salah lebih buruk daripada
     * tidak ada titik. Bila Google mengubah halamannya, ini mengembalikan null
     * dan pemanggil jatuh ke perilaku lama (nama tempat + saran minta shareloc).
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function titikTempat(?string $url): ?array
    {
        if (! $url || ! self::domainGoogle($url)) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $kgmid = is_string($q['kgmid'] ?? null) ? $q['kgmid'] : '';
        $nama  = is_string($q['q'] ?? null) ? trim($q['q']) : '';

        if (! preg_match('~^/[a-z]/[\w-]+$~i', $kgmid) || $nama === '') {
            return null;
        }

        $halaman = $this->ambil('https://www.google.com/maps?' . http_build_query(['q' => $nama, 'kgmid' => $kgmid, 'hl' => 'id']));

        // Halaman Maps memuat daftar hasilnya lewat permintaan susulan yang
        // alamatnya sudah tertulis di <link> pratinjau.
        if (! $halaman || ! preg_match('~<link href="(/search\?tbm=map[^"]+)"~', $halaman, $m)) {
            return null;
        }

        $hasil = $this->ambil('https://www.google.com' . html_entity_decode($m[1]));
        $posisi = $hasil ? strpos($hasil, $kgmid) : false;

        if ($posisi === false) {
            return null;
        }

        // Titik tempat tertulis "[null,null,lat,lng],"0x..:0x.."" di awal
        // entrinya; ambil yang terakhir SEBELUM kgmid supaya milik entri itu.
        preg_match_all('~\[null,null,(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)\],"0x[0-9a-f]+:0x[0-9a-f]+"~', substr($hasil, 0, $posisi), $titik, PREG_SET_ORDER);

        if (! $titik) {
            return null;
        }

        $coord = self_valid_lat_long((float) end($titik)[1], (float) end($titik)[2]);

        return $coord['latitude'] !== null ? $coord : null;
    }

    private function ambil(string $url): ?string
    {
        try {
            $res = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126 Safari/537.36'])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $res->successful() ? $res->body() : null;
    }

    private static function domainGoogle(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return (bool) preg_match('~(^|\.)(google\.[a-z.]+|google|goo\.gl|g\.co)$~', $host)
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
