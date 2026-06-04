<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE sdm_attendance_overrides MODIFY COLUMN type ENUM(
                'cuti',
                'sakit',
                'izin_pagi',
                'izin_sore',
                'izin_setengah_hari',
                'ganti_hari',
                'lembur',
                'tukar_hari',
                'tukar_setengah_hari',
                'penyesuaian_jam'
            ) NOT NULL
        ");

        Schema::table('sdm_attendance_overrides', function (Blueprint $table) {
            $table->date('paired_date')->nullable()->after('tanggal')->comment('Pasangan tanggal untuk tukar_hari / tukar_setengah_hari');
            $table->enum('sesi', ['pagi', 'sore'])->nullable()->after('paired_date')->comment('Sesi 1/2 hari pada tanggal utama');
            $table->enum('paired_sesi', ['pagi', 'sore'])->nullable()->after('sesi')->comment('Sesi 1/2 hari pada tanggal pasangan');
            $table->time('jam_masuk_override')->nullable()->after('paired_sesi')->comment('Penyesuaian jam masuk hari ini');
            $table->time('jam_pulang_override')->nullable()->after('jam_masuk_override')->comment('Penyesuaian jam pulang hari ini');
        });

        // Drop unique index lama biar bisa simpan 2+ tipe override beda untuk 1 karyawan+tanggal (mis. izin_pagi + tukar_hari)
        // Index existing: karyawan_id + tanggal + type. Tetap kepakai — tidak perlu drop.
    }

    public function down(): void
    {
        Schema::table('sdm_attendance_overrides', function (Blueprint $table) {
            $table->dropColumn(['paired_date', 'sesi', 'paired_sesi', 'jam_masuk_override', 'jam_pulang_override']);
        });

        DB::statement("UPDATE sdm_attendance_overrides SET type = 'cuti' WHERE type IN ('tukar_hari','tukar_setengah_hari','penyesuaian_jam')");
        DB::statement("
            ALTER TABLE sdm_attendance_overrides MODIFY COLUMN type ENUM(
                'cuti','sakit','izin_pagi','izin_sore','izin_setengah_hari','ganti_hari','lembur'
            ) NOT NULL
        ");
    }
};
