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
        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');

            $table->string('source_type');
            // opening, purchase, production, repair, adjustment

            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('production_order_id')->nullable();

            $table->decimal('qty_in', 18, 4);
            $table->decimal('qty_remaining', 18, 4);

            $table->decimal('unit_cost', 18, 2);

            $table->timestamps();

            $table->index(['product_id', 'warehouse_id']);

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_layers');
    }
};
