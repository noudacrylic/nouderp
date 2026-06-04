<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagian A — Partial Surat Jalan: satu invoice bisa punya BANYAK surat jalan
 * (partial + auto-SJ sisa). Kolom invoice_id sebelumnya UNIQUE (warisan model
 * 1 invoice = 1 SJ), menyebabkan error 1062 saat InvoicePostingService membuat
 * SJ sisa dengan invoice_id yang sama. Ubah jadi index biasa.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            // FK bersandar pada unique index → drop FK dulu sebelum drop unique.
            $table->dropForeign(['invoice_id']);
            $table->dropUnique('sales_deliveries_invoice_id_unique');
            // Index biasa (non-unique) agar banyak SJ boleh menunjuk 1 invoice.
            $table->index('invoice_id');
            // Re-add FK persis seperti semula (nullOnDelete).
            $table->foreign('invoice_id')
                ->references('id')->on('sales_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropIndex(['invoice_id']);
            $table->unique('invoice_id');
            $table->foreign('invoice_id')
                ->references('id')->on('sales_invoices')
                ->nullOnDelete();
        });
    }
};
