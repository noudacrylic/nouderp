<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pesanan yang disiapkan alat `buat_pesanan` (agen AI WhatsApp). Masukan alatnya disimpan,
 * bukan SO: SO baru dibuat saat balasan dikirim / admin klik "Buat pesanan", dan pagar
 * (stok, harga, ongkir, batas total) diperiksa ulang saat itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_saran_ai', function (Blueprint $table) {
            $table->json('pesanan')->nullable()->after('alasan');
            $table->text('pesanan_ringkasan')->nullable()->after('pesanan');
            $table->unsignedBigInteger('pesanan_so_id')->nullable()->after('pesanan_ringkasan');
        });
    }

    public function down(): void
    {
        Schema::table('crm_saran_ai', function (Blueprint $table) {
            $table->dropColumn(['pesanan', 'pesanan_ringkasan', 'pesanan_so_id']);
        });
    }
};
