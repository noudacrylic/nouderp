<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('midtrans_settings', function (Blueprint $table) {
            // Saklar eksplisit tombol "Bayar QRIS" di Kasir POS. Default false supaya selama
            // Midtrans belum benar-benar live, operator tidak bisa salah klik QRIS dan membuat
            // invoice ter-post tanpa pembayaran (nyangkut BELUM LUNAS). Nyalakan saat siap.
            $table->boolean('pos_qris_enabled')->default(false)->after('show_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('midtrans_settings', function (Blueprint $table) {
            $table->dropColumn('pos_qris_enabled');
        });
    }
};
