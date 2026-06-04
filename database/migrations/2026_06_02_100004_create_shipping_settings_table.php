<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — setting per-provider kurir (singleton per provider), pola seperti MidtransSetting.
 * Satu baris per provider: 'biteship', 'kiriminaja'.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipping_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->unique();       // 'biteship' | 'kiriminaja'
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_production')->default(false); // false = sandbox/test
            $table->string('api_key', 500)->nullable();
            $table->string('base_url', 255)->nullable();
            $table->string('webhook_token', 255)->nullable(); // untuk verifikasi webhook nanti
            $table->json('config')->nullable();               // konfigurasi tambahan per provider
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_settings');
    }
};
