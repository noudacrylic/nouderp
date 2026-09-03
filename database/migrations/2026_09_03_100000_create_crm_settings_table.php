<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();          // 'apicoid'
            $table->boolean('is_enabled')->default(false);
            $table->string('api_key')->nullable();
            $table->string('base_url')->nullable();
            // Kunci HMAC-SHA256 untuk memverifikasi webhook masuk (Tahap 4).
            $table->string('webhook_secret')->nullable();
            // id nomor bisnis default (hasil GET /phone-numbers), boleh kosong
            // → provider memakai nomor utama akun.
            $table->string('default_phone_number_id')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_settings');
    }
};
