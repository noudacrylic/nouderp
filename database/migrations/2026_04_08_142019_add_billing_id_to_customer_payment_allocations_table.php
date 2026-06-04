<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_payment_allocations', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->change();
            $table->foreignId('billing_id')->nullable()->after('invoice_id')->constrained('customer_billings');
            $table->foreignId('sales_order_id')->nullable()->after('billing_id')->constrained('sales_orders');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropForeign(['billing_id']);
            $table->dropColumn(['sales_order_id', 'billing_id']);
            $table->foreignId('invoice_id')->nullable(false)->change();
        });
    }
};
