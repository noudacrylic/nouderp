<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penghitung berapa kali produk dilihat pengunjung etalase — dipakai untuk
 * baris rekomendasi "Sering dilihat". Ditambah via endpoint publik ringan,
 * tanpa menyentuh updated_at (agar cache katalog tak ikut ter-resync).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            $table->unsignedBigInteger('view_count')->default(0)->after('is_featured')->index();
        });
    }

    public function down(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            $table->dropColumn('view_count');
        });
    }
};
