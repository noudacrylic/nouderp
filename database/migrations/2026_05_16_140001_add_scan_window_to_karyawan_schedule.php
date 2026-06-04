<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_karyawan_schedule', function (Blueprint $table) {
            // Window scan reguler — datang
            $table->smallInteger('awal_absen_masuk')->default(90)->after('jam_masuk');
            $table->smallInteger('akhir_absen_masuk')->default(120)->after('late_in_minutes');

            // Window scan reguler — pulang
            $table->smallInteger('awal_absen_pulang')->default(120)->after('jam_pulang');
            $table->smallInteger('akhir_absen_pulang')->default(15)->after('early_out_minutes');

            // Window scan lembur — datang
            $table->smallInteger('awal_absen_lembur_masuk')->default(15)->after('jam_masuk_lembur');
            $table->smallInteger('toleransi_lembur_masuk')->default(0)->after('awal_absen_lembur_masuk');
            $table->smallInteger('akhir_absen_lembur_masuk')->default(120)->after('toleransi_lembur_masuk');

            // Window scan lembur — pulang
            $table->smallInteger('awal_absen_lembur_pulang')->default(160)->after('jam_pulang_lembur');
            $table->smallInteger('toleransi_lembur_pulang')->default(150)->after('awal_absen_lembur_pulang');
            $table->smallInteger('akhir_absen_lembur_pulang')->default(90)->after('toleransi_lembur_pulang');
        });
    }

    public function down(): void
    {
        Schema::table('sdm_karyawan_schedule', function (Blueprint $table) {
            $table->dropColumn([
                'awal_absen_masuk', 'akhir_absen_masuk',
                'awal_absen_pulang', 'akhir_absen_pulang',
                'awal_absen_lembur_masuk', 'toleransi_lembur_masuk', 'akhir_absen_lembur_masuk',
                'awal_absen_lembur_pulang', 'toleransi_lembur_pulang', 'akhir_absen_lembur_pulang',
            ]);
        });
    }
};
