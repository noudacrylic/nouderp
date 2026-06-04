<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            // Drop FK constraint dulu sebelum ubah nullable
            $table->dropForeign(['department_id']);
        });

        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->change();
            $table->foreign('department_id')->references('id')->on('production_departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable(false)->change();
            $table->foreign('department_id')->references('id')->on('production_departments')->restrictOnDelete();
        });
    }
};
