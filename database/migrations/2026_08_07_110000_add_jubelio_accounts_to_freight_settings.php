<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akun saldo & beban layanan untuk Jubelio Shipment.
 *
 * Jurnal ongkir dulu ditulis saat Biteship satu-satunya agregator, jadi akun depositnya
 * satu kolom tanpa nama provider. Sejak Jubelio Shipment jadi satu-satunya yang dipakai,
 * setiap provider perlu akun kasnya sendiri — saldonya prabayar dan terpisah.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('freight_settings', function (Blueprint $table) {
            $table->foreignId('jubelio_saldo_account_id')->nullable()->after('biteship_fee_account_id')
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('jubelio_fee_account_id')->nullable()->after('jubelio_saldo_account_id')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('freight_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jubelio_saldo_account_id');
            $table->dropConstrainedForeignId('jubelio_fee_account_id');
        });
    }
};
