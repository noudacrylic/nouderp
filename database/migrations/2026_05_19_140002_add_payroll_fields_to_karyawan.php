<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->enum('ptkp_category', ['NONE', 'A', 'B', 'C'])->default('NONE')->after('npwp');
            $table->boolean('ikut_bpjs_kesehatan')->default(false)->after('ptkp_category');
            $table->boolean('ikut_bpjs_tk')->default(false)->after('ikut_bpjs_kesehatan');
        });
    }

    public function down(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->dropColumn(['ptkp_category', 'ikut_bpjs_kesehatan', 'ikut_bpjs_tk']);
        });
    }
};
