<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            // Drop FK supaya kolom bisa diubah jadi nullable
            $table->dropForeign(['purchase_invoice_id']);
            $table->dropForeign(['warehouse_id']);
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_invoice_id')->nullable()->change();
            $table->unsignedBigInteger('warehouse_id')->nullable()->change();

            $table->enum('return_type', ['invoice', 'po'])->default('invoice')->after('return_date');
            $table->unsignedBigInteger('purchase_order_id')->nullable()->after('purchase_invoice_id');
            $table->decimal('refund_amount', 18, 2)->default(0)->after('total');
            // JSON log: untuk PO retur, catat decrement supplier_payments.remaining_amount FIFO,
            // supaya void bisa reverse. Format: [{"payment_id":1,"amount":1000.00}, ...]
            $table->json('dp_allocation')->nullable()->after('refund_amount');
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            // Pasang ulang FK
            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
        });

        // Backfill data lama: semua existing rows = type 'invoice', refund_amount = total
        DB::statement("UPDATE purchase_returns SET return_type = 'invoice', refund_amount = total WHERE return_type IS NULL OR return_type = ''");
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropForeign(['purchase_invoice_id']);
            $table->dropForeign(['warehouse_id']);
            $table->dropForeign(['purchase_order_id']);
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn(['return_type', 'purchase_order_id', 'refund_amount', 'dp_allocation']);
            $table->unsignedBigInteger('purchase_invoice_id')->nullable(false)->change();
            $table->unsignedBigInteger('warehouse_id')->nullable(false)->change();
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });
    }
};
