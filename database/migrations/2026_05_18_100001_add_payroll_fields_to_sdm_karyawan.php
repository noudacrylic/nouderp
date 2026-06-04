<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->decimal('bpjs_kesehatan_amount', 15, 2)->default(0)->after('bpjs');
            $table->decimal('bpjs_tk_amount', 15, 2)->default(0)->after('bpjs_kesehatan_amount');
            $table->decimal('pph21_amount', 15, 2)->default(0)->after('bpjs_tk_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->dropColumn(['bpjs_kesehatan_amount', 'bpjs_tk_amount', 'pph21_amount']);
        });
    }
};
