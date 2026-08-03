<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('store_categories', function (Blueprint $table) {
            // Teks pengantar halaman /kategori/{slug} di toko online. Tanpa ini
            // halaman kategori cuma judul + grid produk — tipis di mata Google,
            // padahal justru halaman inilah target kata kunci komersial
            // ("box charger akrilik"), bukan halaman produk satuan.
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('store_categories', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
