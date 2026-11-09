<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alasan Pending manual (ProductionStepTimeLog::ALASAN_PENDING) — dasar neraca jam kerja &
 * skill Pengawasan penilaian Assembling. Keterangan bebasnya tetap di kolom notes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_step_time_logs', function (Blueprint $table) {
            $table->string('alasan', 30)->nullable()->after('event_type');
        });

        // Sakelar owner: alasan baru diwajibkan setelah aturan penilaian dijelaskan ke tim.
        Schema::table('production_settings', function (Blueprint $table) {
            $table->boolean('alasan_pending_wajib')->default(false)->after('testing_mode');
        });
    }

    public function down(): void
    {
        Schema::table('production_step_time_logs', function (Blueprint $table) {
            $table->dropColumn('alasan');
        });
        Schema::table('production_settings', function (Blueprint $table) {
            $table->dropColumn('alasan_pending_wajib');
        });
    }
};
