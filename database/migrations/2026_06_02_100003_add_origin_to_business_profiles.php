<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0 — alamat origin (gudang/toko) untuk perhitungan ongkir.
 * city/postal_code sudah ada di business_profiles. Tambah province + biteship_area_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->string('province', 100)->nullable()->after('city');
            $table->string('biteship_area_id', 100)->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['province', 'biteship_area_id']);
        });
    }
};
