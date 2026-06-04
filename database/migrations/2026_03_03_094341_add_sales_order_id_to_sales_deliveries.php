<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_deliveries', 'sales_order_id')) {
                $table->foreignId('sales_order_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('sales_orders')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('sales_deliveries', 'invoice_id')) {
                $table->foreignId('invoice_id')
                    ->nullable()
                    ->unique()
                    ->constrained('sales_invoices')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');

            $table->dropForeign(['invoice_id']);
            $table->dropColumn('invoice_id');
        });
    }
};
