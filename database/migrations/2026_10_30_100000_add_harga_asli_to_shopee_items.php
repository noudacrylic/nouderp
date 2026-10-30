<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopee tahap 3 (harga): `harga` = current_price (sudah terpotong promo bila ada),
 * `harga_asli` = original_price — harga normal yang diatur penjual, yang dibandingkan
 * dengan Harga Jual kanal Shopee di Analisa ▸ Harga Produk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_items', function (Blueprint $table) {
            $table->decimal('harga_asli', 15, 2)->nullable()->after('harga');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_items', function (Blueprint $table) {
            $table->dropColumn('harga_asli');
        });
    }
};
