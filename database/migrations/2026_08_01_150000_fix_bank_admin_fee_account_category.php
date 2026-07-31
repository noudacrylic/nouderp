<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hanya 5103 Beban Administrasi Bank yang boleh berkategori `bank_admin_fee`.
 *
 * 5290 (Beban Midtrans Gateway) dan 5291 (Beban API Biteship) ikut memakai kategori itu
 * sejak migrasi masing-masing, padahal keduanya bukan biaya admin bank — 5290 menampung
 * MDR payment gateway, 5291 biaya API kurir. Akibatnya form Transfer Antar Bank,
 * Rekonsiliasi Bank, Bayar Gaji, dan Absensi mengisi "Akun Beban Admin" dengan akun mana
 * pun yang kebetulan terambil lebih dulu (praktiknya 5290), sehingga biaya admin bank
 * terbukukan sebagai beban Midtrans bila operator lupa menggantinya.
 *
 * Kategori dikosongkan, BUKAN akunnya dihapus: 5290 tetap dipakai lewat
 * `payment_fee_settings` (kas 1111 → beban 5290) dan 5291 lewat modul pengiriman.
 * Jurnal lama tidak disentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')
            ->whereIn('code', ['5290', '5291'])
            ->where('account_category', 'bank_admin_fee')
            ->update(['account_category' => null]);

        // Pastikan akun yang benar memang bertanda, supaya fallback pencarian tetap ada
        // untuk instalasi yang 5103-nya belum berkategori.
        DB::table('accounts')
            ->where('code', '5103')
            ->update(['account_category' => 'bank_admin_fee']);
    }

    public function down(): void
    {
        DB::table('accounts')
            ->whereIn('code', ['5290', '5291'])
            ->whereNull('account_category')
            ->update(['account_category' => 'bank_admin_fee']);
    }
};
