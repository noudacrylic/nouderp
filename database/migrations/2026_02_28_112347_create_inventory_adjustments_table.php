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
        Schema::create('inventory_adjustments', function (Blueprint $table) {

            $table->id();

            $table->string('adjustment_number')->unique();
            $table->date('date');

            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');

            $table->decimal('qty_system', 18, 4);
            $table->decimal('qty_actual', 18, 4);

            $table->string('status')->default('draft');

            $table->timestamps();

            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses');

            $table->foreign('product_id')
                ->references('id')
                ->on('products');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};
