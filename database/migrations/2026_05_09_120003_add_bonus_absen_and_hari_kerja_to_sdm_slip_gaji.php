<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_slip_gaji', function (Blueprint $table) {
            $table->tinyInteger('hari_kerja_periode')->default(25)->after('gaji_per_hari');
            $table->tinyInteger('hari_bonus_absen')->default(0)->after('hari_libur');
            $table->decimal('bonus_absen_amount', 15, 2)->default(0)->after('tunjangan_bulanan_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sdm_slip_gaji', function (Blueprint $table) {
            $table->dropColumn(['hari_kerja_periode', 'hari_bonus_absen', 'bonus_absen_amount']);
        });
    }
};
