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
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->index(['product_id', 'warehouse_id', 'production_order_id', 'qty_remaining'], 'idx_fifo_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->dropIndex('idx_fifo_lookup');
        });
    }
};
