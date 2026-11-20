<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Putusan owner atas langkah yang timernya janggal (skill Pengawasan → Meja Owner):
 * wajar = dinilai seperti timernya, abaikan = tidak ikut penilaian (mis. timer lupa dijeda),
 * curang = dinilai 0 poin. Kosong = belum diputus (poin ditahan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_steps', function (Blueprint $table) {
            $table->string('penilaian_putusan', 10)->nullable()->after('standar_dicoret_by');
            $table->timestamp('penilaian_putusan_at')->nullable()->after('penilaian_putusan');
            $table->unsignedBigInteger('penilaian_putusan_by')->nullable()->after('penilaian_putusan_at');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_steps', fn (Blueprint $t) => $t->dropColumn(['penilaian_putusan', 'penilaian_putusan_at', 'penilaian_putusan_by']));
    }
};
