<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda kapan resi/label kurir Surat Jalan dicetak (non-marketplace, Biteship).
 * Dipakai filter "Belum/Sudah dicetak" di "Telah Diproses" dan aturan pindah ke
 * "Selesai" mulai H+1 setelah resi di-generate & dicetak.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->timestamp('resi_printed_at')->nullable()->after('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->dropColumn('resi_printed_at');
        });
    }
};
