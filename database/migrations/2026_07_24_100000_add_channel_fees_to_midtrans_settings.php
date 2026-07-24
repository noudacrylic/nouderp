<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tarif & subsidi per metode bayar Midtrans.
     *
     * Model: setting = "dana yang toko tanggung" (subsidi). Pembeli membayar
     * MDR − subsidi (clamp ≥ 0). Di jurnal, Beban Gateway = subsidi tsb.
     *
     * Kolom lama (va_fee, qris_fee_percent, customer_fee_amount) TETAP dipakai
     * sebagai fallback untuk metode yang belum diatur di channel_fees, sehingga
     * akuntansi VA/QRIS yang sudah berjalan tidak berubah.
     */
    public function up(): void
    {
        Schema::table('midtrans_settings', function (Blueprint $table) {
            // { "gopay": {"mdr_flat":0,"mdr_percent":2,"subsidy":null}, "alfamart": {"mdr_flat":5000,"mdr_percent":0,"subsidy":2000}, ... }
            // subsidy null = toko tanggung penuh (pembeli bayar 0).
            $table->json('channel_fees')->nullable()->after('customer_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('midtrans_settings', function (Blueprint $table) {
            $table->dropColumn('channel_fees');
        });
    }
};
