<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
                'lembur'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE sdm_attendance_overrides MODIFY COLUMN type ENUM(
                'cuti',
                'sakit',
                'izin_pagi',
                'izin_sore',
                'lembur'
            ) NOT NULL
        ");
    }
};
