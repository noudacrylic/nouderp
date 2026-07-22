<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dukung BEBERAPA rekening tujuan (mis. BRI auto-email + BCA manual-Telegram).
 * Tiap rekening punya akun kas ERP sendiri agar pembukuan per-bank akurat.
 * Terenkripsi at-rest (ikut kolom config). Kolom tunggal lama tetap ada sbg fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->text('bank_accounts')->nullable()->after('account_holder');
        });
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn('bank_accounts');
        });
    }
};
