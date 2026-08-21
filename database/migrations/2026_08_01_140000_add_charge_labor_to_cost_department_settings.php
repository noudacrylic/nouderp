<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar "bebankan gaji" per divisi. Komponen biasa sudah punya `is_active` di
 * production_cost_components, tapi baris gaji dihitung otomatis dari slip gaji
 * sehingga butuh tempat menyimpan pilihan dibebankan / tidak dibebankan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_cost_department_settings', function (Blueprint $table) {
            $table->boolean('charge_labor')->default(true)->after('basis');
        });
    }

    public function down(): void
    {
        Schema::table('production_cost_department_settings', function (Blueprint $table) {
            $table->dropColumn('charge_labor');
        });
    }
};
