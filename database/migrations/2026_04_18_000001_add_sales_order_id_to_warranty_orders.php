<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_orders', function (Blueprint $table) {
            $table->foreignId('sales_order_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('sales_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warranty_orders', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });
    }
};
