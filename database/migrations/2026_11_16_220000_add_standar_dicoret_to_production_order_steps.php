<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coret sampel KHUSUS waktu standar: owner menyisihkan catatan timer yang menyimpang sebelum
 * rata-rata dihitung. Berbeda dengan sample_excluded_reason (ikut HPP & penilaian) — langkah
 * yang dicoret di sini tetap dinilai seperti biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_steps', function (Blueprint $table) {
            $table->timestamp('standar_dicoret_at')->nullable()->after('sample_excluded_by');
            $table->unsignedBigInteger('standar_dicoret_by')->nullable()->after('standar_dicoret_at');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_steps', fn (Blueprint $t) => $t->dropColumn(['standar_dicoret_at', 'standar_dicoret_by']));
    }
};
