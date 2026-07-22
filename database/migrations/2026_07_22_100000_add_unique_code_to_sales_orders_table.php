<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode unik pembayaran transfer bank (pengganti Midtrans untuk toko online).
 * Nominal Rp1–999 dikurangkan dari grand_total agar total transfer UNIK →
 * dipakai mencocokkan uang masuk ke order. Disimpan di field khusus (BUKAN
 * diskon global) agar tidak bentrok dengan promo cart_total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('unique_code')->nullable()->after('marketplace_fee');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('unique_code');
        });
    }
};
