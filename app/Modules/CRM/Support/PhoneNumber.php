<?php

namespace App\Modules\CRM\Support;

/**
 * Normalisasi nomor telepon ke bentuk yang diterima api.co.id.
 *
 * Dokumentasi mereka TIDAK konsisten: pengiriman satuan memakai '628123456789'
 * (tanpa plus) sementara broadcast mewajibkan E.164 berawalan '+'. Semua kode
 * internal kita menyimpan bentuk TANPA plus; adapter yang menambahkan bila perlu.
 */
class PhoneNumber
{
    /**
     * '0899-8844-666', '+62 899 8844 666', '628998844666' → '628998844666'.
     * Mengembalikan null bila jelas bukan nomor (kosong / terlalu pendek).
     */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // 0812… → 62812…  (nomor lokal Indonesia)
        if (str_starts_with($digits, '0')) {
            $digits = '62' . ltrim($digits, '0');
        }

        // 8123… (tanpa 0 maupun kode negara) → 628123…
        if (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }

        // Nomor Indonesia terpendek pun >= 10 digit setelah kode negara.
        return strlen($digits) >= 10 ? $digits : null;
    }

    /** Bentuk E.164 berawalan '+' — hanya untuk endpoint yang memintanya. */
    public static function e164(?string $raw): ?string
    {
        $n = self::normalize($raw);

        return $n ? '+' . $n : null;
    }
}
