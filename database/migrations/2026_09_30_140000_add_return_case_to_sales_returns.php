<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kasus retur — rincian di bawah Jenis Retur (lihat SalesReturn::CASES). Menggantikan pilihan
 * "Hasil Banding" di form: hasil banding kini DITURUNKAN dari kasus (SalesReturn::CASE_APPEAL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('return_case', 10)->nullable()->after('return_type');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn('return_case');
        });
    }
};
