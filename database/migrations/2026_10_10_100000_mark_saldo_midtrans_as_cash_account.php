<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Saldo Midtrans (1111) jadi akun kas/bank (10 Okt 2026).
 *
 * Akun ini dibuat migrasi Midtrans tanpa is_cash_account, dan karena akun
 * sistem, saklarnya tak bisa dinyalakan dari Bagan Akun. Akibatnya pencairan
 * Midtrans → bank tak bisa dicatat sebagai Transfer (BankTransferService
 * mewajibkan kedua sisi akun kas) dan 1111 tak muncul di rekening lawan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->where('code', '1111')->update(['is_cash_account' => 1]);
    }

    public function down(): void
    {
        DB::table('accounts')->where('code', '1111')->update(['is_cash_account' => 0]);
    }
};
