<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Area ID versi Jubelio Shipment (kode kelurahan 10 digit, mis. 3174011001).
 *
 * Tiap agregator memakai kamus wilayahnya sendiri dan ID-nya tidak bisa saling pakai —
 * karena itu kolomnya per-provider, mengikuti pola biteship_area_id & kiriminaja_area_id
 * yang sudah ada. Kode pos tetap dipakai bersama (Jubelio mewajibkan zipcode terisi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $t) {
            if (!Schema::hasColumn('warehouses', 'jubelio_area_id')) {
                $t->string('jubelio_area_id', 32)->nullable()->after('biteship_area_id');
            }
        });

        Schema::table('customers', function (Blueprint $t) {
            if (!Schema::hasColumn('customers', 'jubelio_area_id')) {
                $t->string('jubelio_area_id', 32)->nullable()->after('biteship_area_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $t) {
            if (Schema::hasColumn('warehouses', 'jubelio_area_id')) {
                $t->dropColumn('jubelio_area_id');
            }
        });

        Schema::table('customers', function (Blueprint $t) {
            if (Schema::hasColumn('customers', 'jubelio_area_id')) {
                $t->dropColumn('jubelio_area_id');
            }
        });
    }
};
