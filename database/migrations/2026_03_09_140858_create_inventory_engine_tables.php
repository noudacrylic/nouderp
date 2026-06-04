<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        // 1. Warehouses
        Schema::dropIfExists('warehouses');
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location')->nullable();
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Product Stocks (Snapshot)
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id');
            $table->foreignId('warehouse_id');
            $table->decimal('qty_on_hand', 14, 4)->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
        });

        // 3. Stock Ledger (History)
        Schema::create('stock_ledgers', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('product_id');
            $table->foreignId('warehouse_id');
            $table->string('reference')->nullable();
            $table->string('type');
            $table->decimal('qty_debit', 14, 4)->default(0);
            $table->decimal('qty_credit', 14, 4)->default(0);
            $table->decimal('balance', 14, 4);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 4. Stock Reservations
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id');
            $table->foreignId('warehouse_id');
            $table->decimal('qty_reserved', 14, 4);
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');
            $table->timestamps();
        });

        // 5. FIFO Layer
        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id');
            $table->foreignId('warehouse_id');
            $table->decimal('qty_in', 14, 4);
            $table->decimal('qty_remaining', 14, 4);
            $table->decimal('unit_cost', 14, 2);
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_layers');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_ledgers');
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('warehouses');
    }
};
