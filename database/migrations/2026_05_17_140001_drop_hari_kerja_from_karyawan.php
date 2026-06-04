<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->dropColumn('hari_kerja_per_bulan');
        });
    }

    public function down(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->integer('hari_kerja_per_bulan')->default(25);
        });
    }
};
