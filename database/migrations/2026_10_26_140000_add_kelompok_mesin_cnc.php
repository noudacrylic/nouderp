<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kelompok mesin CNC: Mesin 1 double head (kepala ganda) untuk produk knockdown, Mesin 2 & 3
 * single head untuk sisanya. Kecepatan keduanya jauh berbeda, jadi:
 *  - skill "Isi antrean CNC" memantau antrean khusus produk kelompok Mesin 1;
 *  - sampel Waktu Produksi yang dikerjakan di mesin lain kelompok dicoret.
 * Diatur di Karyawan Virtual › Manager Produksi › Isi antrean CNC › Pengaturan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('cnc_kepala_ganda')->default(false)->after('produk_andalan');
        });
        Schema::table('production_department_executors', function (Blueprint $table) {
            $table->boolean('kepala_ganda')->default(false)->after('is_active');
        });

        // Isian awal sesuai keterangan user (26 Okt 2026): TB, TBKD, RP, TS, KN — kecuali TB-A4-T2.
        DB::table('products')
            ->where(fn ($q) => $q->where('sku', 'like', 'TB-%')->orWhere('sku', 'like', 'TBKD-%')
                ->orWhere('sku', 'like', 'RP-%')->orWhere('sku', 'like', 'TS-%')->orWhere('sku', 'like', 'KN-%'))
            ->where('sku', 'not like', 'TB-A4-T2%')
            ->where('sku', 'not like', 'TBKD-A4-T2%')
            ->update(['cnc_kepala_ganda' => true]);

        $cnc = DB::table('production_departments')->where('name', 'like', 'CNC%')->value('id');
        if ($cnc) {
            DB::table('production_department_executors')
                ->where('department_id', $cnc)->where('name', 'Mesin 1')
                ->update(['kepala_ganda' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('cnc_kepala_ganda'));
        Schema::table('production_department_executors', fn (Blueprint $table) => $table->dropColumn('kepala_ganda'));
    }
};
