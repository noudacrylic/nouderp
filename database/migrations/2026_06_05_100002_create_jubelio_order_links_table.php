<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan pesanan Jubelio ↔ Sales Order ERP + flag idempotensi tiap tahap
 * (DP / SJ / Invoice / Retur). Mencegah pemrosesan ganda saat webhook & cron
 * sama-sama memproses pesanan yang sama.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('jubelio_order_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('jubelio_salesorder_id')->unique();
            $table->string('jubelio_salesorder_no')->nullable();
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->string('store')->nullable();              // toko/channel asal di Jubelio
            $table->string('last_status')->nullable();        // status Jubelio terakhir diproses
            $table->boolean('dp_posted')->default(false);
            $table->boolean('sj_created')->default(false);
            $table->boolean('invoice_posted')->default(false);
            $table->boolean('return_created')->default(false);
            $table->unsignedBigInteger('jubelio_invoice_id')->nullable();
            $table->unsignedBigInteger('customer_payment_id')->nullable(); // DP yang diposting
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('sales_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jubelio_order_links');
    }
};
