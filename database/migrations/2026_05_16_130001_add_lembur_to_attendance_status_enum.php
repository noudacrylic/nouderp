<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE `sdm_attendance` MODIFY COLUMN `status` ENUM(
            'hadir', 'terlambat', 'setengah_hari', 'pulang_awal',
            'tidak_hadir', 'libur', 'cuti', 'sakit', 'lembur'
        ) NOT NULL DEFAULT 'tidak_hadir'");
    }

    public function down(): void
    {
        DB::statement("UPDATE `sdm_attendance` SET `status` = 'libur' WHERE `status` = 'lembur'");
        DB::statement("ALTER TABLE `sdm_attendance` MODIFY COLUMN `status` ENUM(
            'hadir', 'terlambat', 'setengah_hari', 'pulang_awal',
            'tidak_hadir', 'libur', 'cuti', 'sakit'
        ) NOT NULL DEFAULT 'tidak_hadir'");
    }
};
