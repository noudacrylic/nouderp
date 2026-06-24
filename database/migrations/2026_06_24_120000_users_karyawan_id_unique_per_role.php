<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Izinkan 1 karyawan punya DUA akun: akun kerja (role 'user') + akun absensi
 * (role 'karyawan'). Unique tunggal karyawan_id → unique gabungan (karyawan_id, role).
 *
 * Tetap mencegah duplikat akun dgn role sama untuk karyawan yang sama.
 * NULL karyawan_id (admin/super_admin) tetap boleh banyak (NULL != NULL di unique).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // FK butuh index di karyawan_id → lepas FK dulu, ganti unique, pasang lagi.
            $table->dropForeign(['karyawan_id']);
            $table->dropUnique('users_karyawan_id_unique');
            $table->unique(['karyawan_id', 'role'], 'users_karyawan_id_role_unique');
            $table->foreign('karyawan_id')->references('id')->on('sdm_karyawan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['karyawan_id']);
            $table->dropUnique('users_karyawan_id_role_unique');
            $table->unique('karyawan_id', 'users_karyawan_id_unique');
            $table->foreign('karyawan_id')->references('id')->on('sdm_karyawan')->nullOnDelete();
        });
    }
};
