<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koordinat lat/long untuk gudang (asal) & customer (tujuan).
 * WAJIB untuk kurir instant Biteship (Grab/GoSend/Lalamove) — postal/area saja tidak cukup.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $t) {
            if (!Schema::hasColumn('warehouses', 'latitude'))  $t->decimal('latitude', 10, 7)->nullable()->after('biteship_area_id');
            if (!Schema::hasColumn('warehouses', 'longitude')) $t->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('customers', function (Blueprint $t) {
            if (!Schema::hasColumn('customers', 'latitude'))  $t->decimal('latitude', 10, 7)->nullable()->after('biteship_area_id');
            if (!Schema::hasColumn('customers', 'longitude')) $t->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude']));
    }
};
