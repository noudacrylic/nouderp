<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0 — alamat terstruktur customer untuk integrasi kurir.
 * city sudah ada. biteship_area_id untuk mapping area (booking nanti).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('province', 100)->nullable()->after('city');
            $table->string('district', 100)->nullable()->after('province');
            $table->string('postal_code', 10)->nullable()->after('district');
            $table->string('recipient_phone', 30)->nullable()->after('postal_code');
            $table->string('biteship_area_id', 100)->nullable()->after('recipient_phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['province', 'district', 'postal_code', 'recipient_phone', 'biteship_area_id']);
        });
    }
};
