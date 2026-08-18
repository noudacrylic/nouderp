<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Gaya tampil foto hero: menyatu dengan latar, atau berbingkai.
     *
     * Dua gaya ini dibutuhkan karena dua jenis foto yang sama-sama masuk akal untuk
     * hero berperilaku berlawanan. Foto produk beralas putih tampak paling bagus
     * "menyatu" (latar putihnya dilebur ke gradien hijau, tanpa kotak). Foto produk
     * terpasang di lokasi nyata justru rusak dengan perlakuan itu — seluruh fotonya
     * ikut kehijauan — dan butuh bingkai.
     *
     * Bawaannya menyatu: itu foto yang dipakai sekarang.
     */
    public function up(): void
    {
        Schema::table('store_homepage_settings', function (Blueprint $table) {
            $table->boolean('hero_image_blend')->default(true)->after('hero_image_alt');
        });
    }

    public function down(): void
    {
        Schema::table('store_homepage_settings', function (Blueprint $table) {
            $table->dropColumn('hero_image_blend');
        });
    }
};
