<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->decimal('bonus_absen_harian', 15, 2)->default(0)->after('tunjangan_bulanan');
        });
    }

    public function down(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->dropColumn('bonus_absen_harian');
        });
    }
};
