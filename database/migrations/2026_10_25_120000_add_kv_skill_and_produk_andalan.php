<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - production_orders.kv_skill: skill karyawan virtual yang membuat OP ini (null = bukan
 *   skill / skill lama). Skill pengisi antrean butuh mengenali OP-nya sendiri untuk
 *   menaikkan skor / memindah gudang hasil; dulu cukup "otomatis + prioritas rendah",
 *   sejak ada pengisi CNC kombinasi itu tak lagi unik.
 * - products.produk_andalan: Tingkat 1 skill isi antrean CNC (dipilih manual di
 *   Pengaturan Produksi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('kv_skill', 60)->nullable()->after('created_via')->index();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('produk_andalan')->default(false);
        });

        // Pengisi antrean Assembling yang sudah terlanjur dibuat (penanda lama).
        DB::table('production_orders')
            ->where('created_via', 'auto')->where('score_type', 'priority')->where('priority_level', 'low')
            ->update(['kv_skill' => 'produksi.isi-antrean-rakit']);
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropIndex(['kv_skill']);
            $table->dropColumn('kv_skill');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('produk_andalan');
        });
    }
};
