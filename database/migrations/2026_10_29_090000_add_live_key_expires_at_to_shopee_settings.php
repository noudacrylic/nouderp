<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live API Partner Key Shopee punya masa berlaku (lihat Console › App). Tanggalnya diisi di
 * Pengaturan › Integrasi › Shopee supaya `shopee:ingatkan-kedaluwarsa` bisa mengingatkan
 * lewat Telegram sebelum kunci mati.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->date('live_key_expires_at')->nullable()->after('live_partner_key');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropColumn('live_key_expires_at');
        });
    }
};
