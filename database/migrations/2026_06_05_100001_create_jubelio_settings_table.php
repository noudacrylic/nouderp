<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi Jubelio (omnichannel marketplace) — setting singleton (1 baris, id=1),
 * pola seperti MidtransSetting. Kredensial login (token Jubelio 12 jam di-cache),
 * default gudang/lokasi/customer fallback, dan secret webhook.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('jubelio_settings', function (Blueprint $table) {
            $table->id();
            $table->string('username')->nullable();
            $table->text('password')->nullable();              // encrypted cast di model
            $table->text('access_token')->nullable();          // token Bearer (12 jam), di-cache
            $table->timestamp('token_expires_at')->nullable();
            $table->string('base_url')->nullable();            // default https://api2.jubelio.com
            $table->boolean('is_production')->default(false);
            $table->boolean('is_active')->default(false);
            $table->string('webhook_secret')->nullable();      // verifikasi hash_hmac sha256
            $table->unsignedBigInteger('default_warehouse_id')->nullable(); // gudang ERP untuk SO/SJ
            $table->integer('default_location_id')->nullable();             // location_id Jubelio
            $table->unsignedBigInteger('default_customer_id')->nullable();  // customer marketplace fallback
            $table->json('config')->nullable();                // last_sync, dll
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jubelio_settings');
    }
};
