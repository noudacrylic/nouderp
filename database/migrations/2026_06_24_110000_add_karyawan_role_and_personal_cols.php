<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Role baru 'karyawan' (khusus PWA absensi, terpisah total dari 'user').
 * - Kolom foto profil & foto KTP di sdm_karyawan (diisi saat self-register).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin','admin','user','karyawan') NOT NULL DEFAULT 'user'");

        Schema::table('sdm_karyawan', function (Blueprint $table) {
            if (! Schema::hasColumn('sdm_karyawan', 'foto_path')) {
                $table->string('foto_path')->nullable()->after('bpjs');
            }
            if (! Schema::hasColumn('sdm_karyawan', 'ktp_path')) {
                $table->string('ktp_path')->nullable()->after('foto_path');
            }
        });
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin','admin','user') NOT NULL DEFAULT 'user'");

        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->dropColumn(['foto_path', 'ktp_path']);
        });
    }
};
