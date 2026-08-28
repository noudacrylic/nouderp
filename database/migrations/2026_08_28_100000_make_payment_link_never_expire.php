<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan bayar (/pay/{token}) tidak lagi punya tenggat sendiri.
 *
 * Yang memang berumur pendek adalah PERCOBAAN BAYAR-nya: QRIS/VA yang terbit saat
 * pembeli menekan Bayar, dan batas itu sudah dikirim ke Midtrans lewat payload
 * `expiry` di MidtransService. Menaruh tenggat kedua di baris tautannya membuat
 * alamat yang sudah tersebar ke pembeli mati padahal pesanannya masih hidup.
 *
 * Karena itu `expired_at` jadi nullable, dan tautan lama yang masih menggantung
 * ikut dibebaskan supaya tetap bisa dibuka pembeli yang menyimpannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('midtrans_transactions', function (Blueprint $table) {
            $table->dateTime('expired_at')->nullable()->change();
        });

        DB::table('midtrans_transactions')
            ->where('source', 'link')
            ->where('status', 'pending')
            ->update(['expired_at' => null]);
    }

    public function down(): void
    {
        // Baris tanpa tenggat diberi tenggat sementara agar kolomnya bisa NOT NULL lagi.
        DB::table('midtrans_transactions')
            ->whereNull('expired_at')
            ->update(['expired_at' => now()->addDays(7)]);

        Schema::table('midtrans_transactions', function (Blueprint $table) {
            $table->dateTime('expired_at')->nullable(false)->change();
        });
    }
};
