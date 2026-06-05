<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * is_sellable: produk boleh dijual langsung (muncul di Kasir/POS, Penawaran,
 * Faktur). Default true. Bila false → disembunyikan dari form jual, tapi tetap
 * tersedia untuk produksi/komponen bundle/stok (mis. produk setengah jadi,
 * komponen wajib, pelengkap bundle).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'is_sellable')) {
                $table->boolean('is_sellable')->default(true)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'is_sellable')) {
                $table->dropColumn('is_sellable');
            }
        });
    }
};
