<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kesepakatan "keep stock": pembeli setuju memesan barang yang stoknya belum ada.
 *
 * Tautan pembayaran boleh disebar sejak SO masih draft, dan draft belum menahan stok.
 * Karena itu pembayaran ditolak bila stoknya sudah tidak cukup — kecuali SO ini memang
 * disepakati sebagai pesanan yang stoknya menyusul, yang ditandai lewat kolom ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->boolean('allow_backorder')->default(false)->after('min_dp_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('allow_backorder');
        });
    }
};
