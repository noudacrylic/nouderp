<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Siapa yang menentukan jenis produk sebuah kata kunci: alias (cocok kata, kasar), ai (Haiku),
 * riset (dari riset jenis itu), manual (diubah orang — tak pernah ditimpa). Kata GSC lama
 * dikelompokkan alias → dikelompokkan ulang AI. Jenis "Umum / Toko Akrilik" untuk kata yang
 * tidak menunjuk satu produk (mis. "akrilik semarang").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_kata_kunci', function (Blueprint $t) {
            $t->string('jenis_oleh', 10)->nullable()->after('seo_jenis_id');
        });
        DB::table('seo_kata_kunci')->where('sumber', 'gsc')->whereNotNull('seo_jenis_id')->update(['jenis_oleh' => 'alias']);
        DB::table('seo_kata_kunci')->where('sumber', 'riset')->update(['jenis_oleh' => 'riset']);
        DB::table('seo_kata_kunci')->where('sumber', 'manual')->whereNotNull('seo_jenis_id')->update(['jenis_oleh' => 'manual']);

        DB::table('seo_jenis')->insertOrIgnore([
            'nama' => 'Umum / Toko Akrilik', 'aktif' => true, 'urutan' => 0,
            'petunjuk' => 'kata umum tentang akrilik / toko / jasa akrilik yang tidak menunjuk satu produk (mis. akrilik semarang, jasa potong akrilik)',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('seo_jenis')->where('nama', 'Umum / Toko Akrilik')->delete();
        Schema::table('seo_kata_kunci', fn (Blueprint $t) => $t->dropColumn('jenis_oleh'));
    }
};
