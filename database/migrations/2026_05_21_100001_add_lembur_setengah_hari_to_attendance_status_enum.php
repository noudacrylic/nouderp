<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE `sdm_attendance` MODIFY COLUMN `status` ENUM(
            'hadir', 'terlambat', 'setengah_hari', 'pulang_awal',
            'tidak_hadir', 'libur', 'cuti', 'sakit', 'lembur', 'lembur_setengah_hari'
        ) NOT NULL DEFAULT 'tidak_hadir'");
    }

    public function down(): void
    {
        DB::statement("UPDATE `sdm_attendance` SET `status` = 'lembur' WHERE `status` = 'lembur_setengah_hari'");
        DB::statement("ALTER TABLE `sdm_attendance` MODIFY COLUMN `status` ENUM(
            'hadir', 'terlambat', 'setengah_hari', 'pulang_awal',
            'tidak_hadir', 'libur', 'cuti', 'sakit', 'lembur'
        ) NOT NULL DEFAULT 'tidak_hadir'");
    }
};
