<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potongan marketplace yang dibukukan SUSULAN setelah pesanan dicairkan.
 *
 * Saat pesanan selesai, rincian escrow Shopee dari Jubelio kadang belum lengkap (affiliate,
 * premi pengiriman menyusul beberapa jam kemudian). Pencairan sudah terlanjur menjurnal dana
 * masuk yang terlalu besar, sehingga Saldo Penjualan di ERP lebih besar dari Saldo Penjual asli.
 * Begitu rincian final tiba, MarketplaceEngineService::koreksiKeRincianFinal() membukukan
 * selisihnya dan mencatat nominalnya di sini.
 *
 * Kolom terpisah dari `marketplace_fee` karena faktur gaya LAMA memakai `marketplace_fee`
 * juga untuk menghitung nilai kotor (grand_total + marketplace_fee). Menambahkan koreksi ke
 * sana akan membuat rekonsiliasi membukukan selisih yang sama sekali lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->decimal('mp_fee_koreksi', 15, 2)->default(0)->after('fee_at_settlement');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('mp_fee_koreksi');
        });
    }
};
