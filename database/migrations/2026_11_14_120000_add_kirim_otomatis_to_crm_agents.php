<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Saran AI Tahap 3 — kirim otomatis DI LUAR JAM KERJA (keputusan owner 14 Nov 2026).
 *
 * crm_agents.kirim_otomatis: mati | bayangan | otomatis.
 *   bayangan = saran ditandai "akan terkirim" tapi TIDAK dikirim — bahan cek sebelum
 *   Asisten AI Shopee dimatikan di Seller Centre (API Shopee tidak bisa mematikannya).
 * crm_saran_ai.otomatis: null | bayangan | terkirim | gagal — jejak per saran, dipakai
 *   daftar "jawaban AI semalam" di halaman Periksa Saran AI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_agents', function (Blueprint $table) {
            $table->string('kirim_otomatis', 10)->default('mati')->after('is_active');
        });
        Schema::table('crm_saran_ai', function (Blueprint $table) {
            $table->string('otomatis', 10)->nullable()->after('status');
            $table->index('otomatis');
        });
    }

    public function down(): void
    {
        Schema::table('crm_saran_ai', function (Blueprint $table) {
            $table->dropIndex(['otomatis']);
            $table->dropColumn('otomatis');
        });
        Schema::table('crm_agents', function (Blueprint $table) {
            $table->dropColumn('kirim_otomatis');
        });
    }
};
