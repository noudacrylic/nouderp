<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda kapan resi marketplace dicetak — untuk filter "Belum/Sudah dicetak" di
 * "Telah Diproses" dan fallback pindah ke "Dikirim" (resi dicetak + H+1) bila Jubelio
 * belum melaporkan status shipped. Diisi otomatis saat klik Cetak Resi (toggle manual).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->timestamp('resi_printed_at')->nullable()->after('wms_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->dropColumn('resi_printed_at');
        });
    }
};
