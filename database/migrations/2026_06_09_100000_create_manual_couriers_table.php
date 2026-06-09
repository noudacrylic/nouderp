<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jasa kirim manual — kurir yang tidak dijangkau API (Biteship/KiriminAja),
 * mis. ekspedisi lokal / cargo. Hanya daftar nama; ongkir diisi manual saat transaksi.
 * Muncul berdampingan dengan kurir API di kartu Pengiriman SO/Invoice (tinggal pilih).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('manual_couriers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);                 // nama tampil, mis. "J&T Cargo"
            $table->string('code', 60)->unique();        // slug, dipakai di shipping_courier_code
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_couriers');
    }
};
