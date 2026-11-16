<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parameter penilaian kinerja produksi (pita poin, batas perlu-dicek, ambang & bonus tiap
 * tingkat) — diatur owner dari Dashboard Penilaian. Kosong = default di PenilaianKinerjaService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_settings', function (Blueprint $table) {
            $table->json('penilaian_param')->nullable()->after('alasan_pending_wajib');
        });
    }

    public function down(): void
    {
        Schema::table('production_settings', function (Blueprint $table) {
            $table->dropColumn('penilaian_param');
        });
    }
};
