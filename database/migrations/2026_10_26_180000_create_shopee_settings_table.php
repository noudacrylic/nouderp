<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci app Shopee Open Platform diisi dari Pengaturan › Integrasi › Shopee (bukan .env),
 * seperti integrasi lain. Pasangan Test & Live disimpan terpisah supaya pindah lingkungan
 * cukup satu pilihan; Partner Key terenkripsi (APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_settings', function (Blueprint $table) {
            $table->id();
            $table->string('env', 10)->default('test');
            $table->unsignedBigInteger('test_partner_id')->nullable();
            $table->text('test_partner_key')->nullable();
            $table->unsignedBigInteger('live_partner_id')->nullable();
            $table->text('live_partner_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_settings');
    }
};
