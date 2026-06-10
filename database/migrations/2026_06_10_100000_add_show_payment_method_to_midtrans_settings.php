<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('midtrans_settings', function (Blueprint $table) {
            // Saat true: cetak Invoice/SO menampilkan cara pembayaran Midtrans (link + QR).
            // Saat false: menampilkan nomor rekening (transfer manual) — dipakai selama Midtrans
            // masih review bisnis sehingga ERP tetap bisa dipakai.
            $table->boolean('show_payment_method')->default(false)->after('is_production');
        });
    }

    public function down(): void
    {
        Schema::table('midtrans_settings', function (Blueprint $table) {
            $table->dropColumn('show_payment_method');
        });
    }
};
