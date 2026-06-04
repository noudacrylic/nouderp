<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_karyawan_schedule', function (Blueprint $table) {
            $table->boolean('has_lembur')->default(false)->after('akhir_absen_lembur_pulang');
            $table->time('jam_istirahat_lembur_start')->nullable()->after('has_lembur');
            $table->time('jam_istirahat_lembur_end')->nullable()->after('jam_istirahat_lembur_start');
        });
    }

    public function down(): void
    {
        Schema::table('sdm_karyawan_schedule', function (Blueprint $table) {
            $table->dropColumn(['has_lembur', 'jam_istirahat_lembur_start', 'jam_istirahat_lembur_end']);
        });
    }
};
