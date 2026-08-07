<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran tempo (bayar belakangan) untuk Sales Order.
 *
 * Pesanan tempo boleh diproses & dikirim tanpa menunggu uang masuk — itulah gunanya. Karena
 * itu ia melewati gerbang "Belum Bayar" MAUPUN "Belum Lunas" di Pemrosesan Pesanan.
 *
 * Tanggal jatuh temponya disimpan (bukan dihitung ulang tiap kali dibaca) supaya termin yang
 * disepakati tidak ikut berubah kalau tanggal SO belakangan dikoreksi.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->boolean('is_tempo')->default(false)->after('allow_backorder');
            $table->unsignedSmallInteger('tempo_days')->nullable()->after('is_tempo');
            $table->date('tempo_due_date')->nullable()->after('tempo_days');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['is_tempo', 'tempo_days', 'tempo_due_date']);
        });
    }
};
