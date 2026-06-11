<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiriminAja pakai ID kecamatan (beda dari area_id Biteship). Simpan terpisah agar
 * kedua provider bisa hidup berdampingan: customer/gudang/profil punya area_id Biteship
 * DAN kecamatan_id KiriminAja. Kolom diletakkan setelah biteship_area_id (bila ada).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'warehouses', 'business_profiles'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'kiriminaja_area_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $col = $t->string('kiriminaja_area_id', 100)->nullable();
                    if (Schema::hasColumn($t->getTable(), 'biteship_area_id')) {
                        $col->after('biteship_area_id');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['customers', 'warehouses', 'business_profiles'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'kiriminaja_area_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('kiriminaja_area_id');
                });
            }
        }
    }
};
