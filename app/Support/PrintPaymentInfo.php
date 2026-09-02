<?php

namespace App\Support;

use App\Models\MidtransSetting;

/**
 * Info pembayaran yang tercetak di dokumen (Pesanan Penjualan / Faktur).
 *
 * Dokumen boleh menampilkan link+QR pembayaran, rekening transfer, atau keduanya —
 * perusahaan besar umumnya minta nomor rekening, pembeli ritel lebih suka link.
 * Setting Midtrans cuma menentukan BAWAAN; pilihannya bisa ditukar per cetakan lewat
 * query `?bayar=link|rekening|dua|tanpa` yang juga dipakai tombol di toolbar cetak
 * (supaya PDF — dirender tanpa JS toolbar — ikut pilihan yang sama).
 */
class PrintPaymentInfo
{
    /** Nilai query `bayar` → mode internal. */
    private const ALIASES = [
        'link'     => 'link',
        'qr'       => 'link',
        'bank'     => 'bank',
        'rekening' => 'bank',
        'dua'      => 'both',
        'both'     => 'both',
        'semua'    => 'both',
        'tanpa'    => 'none',
        'none'     => 'none',
    ];

    /**
     * Daftar rekening pembayaran: relasi multi-bank, fallback ke kolom single-bank lama.
     *
     * @return array<int,array{bank_name:string,account_number:string,holder:?string}>
     */
    public static function banks($profile): array
    {
        if (!$profile) {
            return [];
        }

        $banks = [];

        if (method_exists($profile, 'relationLoaded')) {
            if (!$profile->relationLoaded('bankAccounts')) {
                $profile->load('bankAccounts');
            }

            foreach ($profile->bankAccounts as $row) {
                if (empty($row->bank_name) || empty($row->account_number)) {
                    continue;
                }
                $banks[] = [
                    'bank_name'      => $row->bank_name,
                    'account_number' => $row->account_number,
                    'holder'         => $row->holder,
                ];
            }
        }

        if (empty($banks) && !empty($profile->bank_name) && !empty($profile->bank_account_number)) {
            $banks[] = [
                'bank_name'      => $profile->bank_name,
                'account_number' => $profile->bank_account_number,
                'holder'         => $profile->bank_account_holder,
            ];
        }

        return $banks;
    }

    /**
     * Mode yang benar-benar dicetak: 'link' | 'bank' | 'both' | 'none'.
     * Sudah dikunci ke yang datanya tersedia — minta 'bank' tapi rekening belum diisi
     * jatuh balik ke link, bukan menghasilkan blok kosong.
     */
    public static function mode(bool $hasLink, bool $hasBank, ?string $requested = null): string
    {
        $key  = $requested !== null ? strtolower(trim($requested)) : null;
        $mode = self::ALIASES[$key] ?? (MidtransSetting::singleton()->show_payment_method ? 'link' : 'bank');

        if ($mode === 'both' && !($hasLink && $hasBank)) {
            $mode = $hasLink ? 'link' : 'bank';
        }
        if ($mode === 'link' && !$hasLink) {
            $mode = $hasBank ? 'bank' : 'none';
        }
        if ($mode === 'bank' && !$hasBank) {
            $mode = $hasLink ? 'link' : 'none';
        }

        return $mode;
    }
}
