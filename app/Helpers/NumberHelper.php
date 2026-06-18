<?php

if (!function_exists('format_qty')) {

    function format_qty($number)
    {
        if ($number === null) {
            return 0;
        }

        if ($number == (int) $number) {
            return number_format($number, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($number, 4, ',', '.'), '0'), ',');
    }
}


if (!function_exists('qty_value')) {

    /**
     * Nilai qty untuk ATRIBUT value input <input type="number"> / HTML5.
     * Desimal pakai titik, TANPA pemisah ribuan (machine-readable), trailing zero dibuang.
     *
     * PENTING: jangan pakai format_qty() di input number — format_qty() memakai titik
     * sebagai pemisah ribuan (mis. 1009 → "1.009"), dan browser akan menafsirkannya
     * sebagai desimal (1.009) sehingga perhitungan salah.
     */
    function qty_value($number)
    {
        if ($number === null || $number === '') {
            return '0';
        }

        return rtrim(rtrim(number_format((float) $number, 4, '.', ''), '0'), '.') ?: '0';
    }
}


if (!function_exists('format_money')) {

    function format_money($number)
    {
        if ($number === null) {
            return 'Rp 0';
        }

        return 'Rp ' . number_format($number, 0, ',', '.');
    }
}


if (!function_exists('format_number')) {

    function format_number($number)
    {
        if ($number === null) {
            return 0;
        }

        return number_format($number, 0, ',', '.');
    }
}

if (!function_exists('format_rupiah')) {

    function format_rupiah($number)
    {
        if ($number === null) {
            return 0;
        }

        return number_format($number, 0, ',', '.');
    }
}

if (!function_exists('list_url')) {

    /**
     * Ambil URL halaman index/list yang terakhir dikunjungi user (lengkap dengan
     * filter query string) dari session. Fallback ke route() biasa kalau session
     * belum ada. Dipakai untuk redirect setelah finish-setup/update supaya filter
     * di list user tidak hilang.
     *
     * Session key di-populate oleh middleware RememberListUrl.
     */
    function list_url(string $routeName, mixed $fallbackParams = []): string
    {
        $stored = session("list_url:$routeName");
        if ($stored && is_string($stored)) {
            try {
                // Untuk route dengan parameter (mis. `sdm.attendance.index` butuh periode_id),
                // bandingkan path stored vs fallback URL — match strict via prefix sebelum query string.
                $fallbackPath = parse_url(route($routeName, $fallbackParams, false), PHP_URL_PATH) ?: '';
                $storedPath = parse_url($stored, PHP_URL_PATH) ?: '';
                if ($storedPath === $fallbackPath) {
                    return $stored;
                }
            } catch (\Throwable $e) {
                // Route butuh param tapi tidak diberikan — abaikan stored, fallback ke route()
            }
        }
        return route($routeName, $fallbackParams);
    }
}

if (!function_exists('clean_number')) {

    function clean_number($string)
    {
        if (!$string) return 0;
        $val = (string) $string;

        // 1. If has comma, it's definitely Indonesian format (comma as decimal)
        if (str_contains($val, ',')) {
            $val = str_replace('.', '', $val); // remove thousand dots
            $val = str_replace(',', '.', $val); // comma to dot
            return (float) $val;
        }

        // 2. No comma. Only dots or no separators.
        // If multiple dots, they are thousands.
        if (substr_count($val, '.') > 1) {
            return (float) str_replace('.', '', $val);
        }

        // 3. Single dot case. Ambiguous: 1.000 (thousand) or 1000.50 (decimal)
        if (str_contains($val, '.')) {
            // Heuristic for Indonesian ERP:
            // If dot is followed by exactly 3 digits at the end, it's likely thousand.
            // UNLESS it's something like 0.123 or small numbers?
            // Actually, for ERP prices, 1.000 is always one thousand.
            // But browsers output 1000.00 for type="number".
            
            if (preg_match('/\.\d{3}$/', $val)) {
                // If it's something like "1.234", "100.000", etc.
                // We check if there's anything before the dot that's more than 3? No.
                // But what if it's "1.234" (one point two three four)? 
                // In Rupiah, we don't use 3 decimals often.
                return (float) str_replace('.', '', $val);
            }
            // Otherwise, keep the dot (e.g. 10.5, 1000.75, 0.5)
        }

        return (float) $val;
    }
}

if (!function_exists('parse_lat_long')) {
    /**
     * Ekstrak koordinat dari input bebas: link Google Maps ATAU string "lat,long".
     * Mendukung pola `@lat,long`, `q=lat,long`, `!3dlat!4dlong`, atau "lat, long" polos.
     * Return ['latitude'=>?float, 'longitude'=>?float] (null bila tak terdeteksi/valid).
     *
     * Catatan: link pendek (maps.app.goo.gl) tidak memuat koordinat → tak bisa di-parse;
     * user perlu paste koordinat penuh atau link panjang.
     */
    function parse_lat_long(?string $input): array
    {
        $null = ['latitude' => null, 'longitude' => null];
        if (!$input) return $null;
        $s = trim($input);
        $L = '(-?\d{1,3}\.\d+)'; // angka desimal koordinat

        // Urutan dari yang paling spesifik → umum.
        $patterns = [
            '/!3d' . $L . '!4d' . $L . '/',                       // Google Maps place: !3dlat!4dlong
            '/@' . $L . ',' . $L . '/',                           // Google Maps view:  @lat,long
            '/[?&](?:q|ll|center|destination|daddr)=' . $L . ',' . $L . '/', // query: ?q=lat,long
            '/' . $L . '\s*,\s*' . $L . '/',                      // polos: "lat, long"
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $s, $m)) {
                $coord = self_valid_lat_long((float) $m[1], (float) $m[2]);
                if ($coord['latitude'] !== null) return $coord;
            }
        }
        return $null;
    }

    function self_valid_lat_long(float $lat, float $long): array
    {
        if ($lat >= -90 && $lat <= 90 && $long >= -180 && $long <= 180) {
            return ['latitude' => $lat, 'longitude' => $long];
        }
        return ['latitude' => null, 'longitude' => null];
    }
}
