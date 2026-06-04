<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan nama toko/channel Jubelio → customer marketplace ERP.
 * Memenuhi aturan "Nama Pelanggan mengikuti nama Marketplace": pesanan dari toko
 * tertentu di Jubelio dibuatkan SO atas nama customer marketplace yang dipetakan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('jubelio_channel_maps', function (Blueprint $table) {
            $table->id();
            $table->string('store')->unique();                // nama toko/channel Jubelio
            $table->unsignedBigInteger('customer_id');        // customer marketplace ERP
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jubelio_channel_maps');
    }
};
