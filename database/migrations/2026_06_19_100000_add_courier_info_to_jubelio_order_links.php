<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Info kurir yang ditarik dari pesanan Jubelio SAAT SINKRON (sebelum diproses),
 * agar operator bisa lihat nama kurir & menandai pesanan instant lebih awal di
 * "Pemrosesan Pesanan". `shipper` (nama kurir) sudah ada dari migration WMS —
 * di sini cukup tambah flag instant.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->boolean('is_instant_courier')->default(false)->after('shipper');
        });
    }

    public function down(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->dropColumn('is_instant_courier');
        });
    }
};
