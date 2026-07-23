<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `unique_code` kini bisa NEGATIF.
 *
 * Transfer bank: kode unik MENGURANGI total (pembeli bayar sedikit lebih murah).
 * QRIS (QRISLY): penyedia MENAMBAHKAN selisih uniknya sendiri pada nominal QR
 * (mis. minta 79.900 → QR jadi 79.903) sebagai penanda pencocokan. Supaya total
 * pesanan sama persis dengan yang dibayar pembeli, selisih itu disimpan sebagai
 * nilai negatif — rumus `grand = ... - unique_code` otomatis menambah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->smallInteger('unique_code')->nullable()->default(null)->change();
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->integer('unique_code')->default(0)->change();
        });

        Schema::table('web_payments', function (Blueprint $table) {
            $table->smallInteger('unique_code')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('unique_code')->nullable()->default(null)->change();
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->unsignedInteger('unique_code')->default(0)->change();
        });

        Schema::table('web_payments', function (Blueprint $table) {
            $table->unsignedSmallInteger('unique_code')->default(0)->change();
        });
    }
};
