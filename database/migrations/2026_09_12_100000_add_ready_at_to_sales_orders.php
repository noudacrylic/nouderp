<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Barang siap diambil" mendapat kolomnya sendiri.
 *
 * Sebelumnya notifikasi siap-diambil dipicu oleh `pickup_status = 'pending'`. Kolom itu
 * terisi saat SO DIKONFIRMASI — untuk pesanan toko online bahkan sebelum pembeli membayar —
 * sehingga pesan "silakan diambil" berangkat di detik pesanan dibuat. Kesiapan barang adalah
 * fakta yang berbeda dari terbitnya kode booking, dan karena itu disimpan terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('ready_at')->nullable()->after('measured_by');
            $table->unsignedBigInteger('ready_by')->nullable()->after('ready_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['ready_at', 'ready_by']);
        });
    }
};
