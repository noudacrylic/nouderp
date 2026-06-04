<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->decimal('tunjangan_pegawai', 14, 2)->default(0)->after('gaji_pokok');
        });
    }

    public function down(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->dropColumn('tunjangan_pegawai');
        });
    }
};
