<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produk yang ditampilkan di layar Kasir (9 Okt 2026).
 *
 * Banyak varian hanya dijual online (mis. ukuran 40x30 punya belasan varian
 * packing, yang dijual langsung di toko cuma varian ambil di toko). Semuanya
 * membanjiri kasir. Ini tanda tampilan saja — produknya tetap bisa dijual di
 * SO, marketplace, dan toko online.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('tampil_di_kasir')->default(true)->after('is_sellable');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tampil_di_kasir');
        });
    }
};
