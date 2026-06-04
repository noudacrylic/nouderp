<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_slip_gaji', function (Blueprint $table) {
            if (Schema::hasColumn('sdm_slip_gaji', 'kasbon_payment')) {
                $table->dropColumn('kasbon_payment');
            }
        });

        Schema::dropIfExists('sdm_kasbon');
    }

    public function down(): void
    {
        // Tidak menyediakan rollback. Kasbon dihapus permanen dari modul SDM.
        // Pengelolaan kasbon karyawan dipindah ke modul Kas & Bank lewat akun "Kasbon Karyawan".
    }
};
