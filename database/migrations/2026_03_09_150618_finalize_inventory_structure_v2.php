<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // 1. Stock Ledger - Rename Columns
        Schema::table('stock_ledgers', function (Blueprint $table) {
            $table->renameColumn('qty_debit', 'qty_in');
            $table->renameColumn('qty_credit', 'qty_out');
        });

        // 2. Stock Reservations - Recreate to match final structure
        Schema::dropIfExists('stock_reservations');
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id');
            $table->foreignId('warehouse_id');
            $table->foreignId('sales_order_id');
            $table->decimal('qty', 14, 4);
            $table->string('status')->default('pending'); // manual status tracking if needed
            $table->timestamps();
        });

        // 3. Stock Shipments - New Table
        Schema::create('stock_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id');
            $table->foreignId('warehouse_id');
            $table->foreignId('delivery_id');
            $table->decimal('qty', 14, 4);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_shipments');

        // Rolling back reservations might be complex, skipping since this is a dev refactor

        Schema::table('stock_ledgers', function (Blueprint $table) {
            $table->renameColumn('qty_in', 'qty_debit');
            $table->renameColumn('qty_out', 'qty_credit');
        });
    }
};
