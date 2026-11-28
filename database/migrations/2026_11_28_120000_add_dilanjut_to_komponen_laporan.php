<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assembling menekan Lanjutkan saat susulan belum dipotong CNC (owner 28 Nov 2026): peringatan,
 * bukan blokir. Pilihan "batalkan susulan" (status dibatalkan) / "lanjut dulu" dicatat di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_komponen_laporan', function (Blueprint $t) {
            $t->dateTime('dilanjut_at')->nullable()->after('material_addition_id');
            $t->unsignedBigInteger('dilanjut_by')->nullable()->after('dilanjut_at');
        });
    }

    public function down(): void
    {
        Schema::table('production_komponen_laporan', function (Blueprint $t) {
            $t->dropColumn(['dilanjut_at', 'dilanjut_by']);
        });
    }
};
