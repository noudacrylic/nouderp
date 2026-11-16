<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merk akrilik per OP — dipilih operator CNC saat Mulai (tanpa mengubah BOM/SKU bahan).
 * Dasar membandingkan lama kerja per merk (mis. MAG: kertas pelindung licin, solasi tak nempel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_settings', function (Blueprint $table) {
            $table->json('merk_akrilik')->nullable()->after('alasan_pending_wajib');
            $table->boolean('merk_akrilik_wajib')->default(false)->after('merk_akrilik');
        });
        Schema::table('production_orders', function (Blueprint $table) {
            $table->json('merk_bahan')->nullable()->after('notes');
        });

        DB::table('production_settings')->update([
            'merk_akrilik' => json_encode(['MAG', 'NN', 'Tiger', 'Pronamax', 'Neuglass', 'Tanpa Merk']),
        ]);
    }

    public function down(): void
    {
        Schema::table('production_orders', fn (Blueprint $t) => $t->dropColumn('merk_bahan'));
        Schema::table('production_settings', fn (Blueprint $t) => $t->dropColumn(['merk_akrilik', 'merk_akrilik_wajib']));
    }
};
