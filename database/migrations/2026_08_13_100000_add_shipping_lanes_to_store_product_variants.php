<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jalur pengiriman yang dilayani tiap varian.
 *
 * Latar: box mahar akrilik pecah bila dikirim ekspedisi dengan packing bubble tipis.
 * Varian "Tanpa Kardus" karena itu hanya boleh diambil/instant, sedangkan varian
 * "Packing kayu" ditujukan untuk kurir. Penandanya di sini, bukan di nama varian,
 * supaya etalase punya sumber yang bisa dipercaya untuk mengunci pilihan di checkout.
 *
 * allow_pickup mewakili SATU jalur non-kurir: instant DAN ambil di toko (keduanya
 * berangkat tanpa ekspedisi, jadi risikonya sama).
 *
 * Bawaan keduanya true → seluruh katalog lama tidak berubah perilaku.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_variants', function (Blueprint $table) {
            $table->boolean('allow_courier')->default(true)->after('option_values');
            $table->boolean('allow_pickup')->default(true)->after('allow_courier');
        });
    }

    public function down(): void
    {
        Schema::table('store_product_variants', function (Blueprint $table) {
            $table->dropColumn(['allow_courier', 'allow_pickup']);
        });
    }
};
