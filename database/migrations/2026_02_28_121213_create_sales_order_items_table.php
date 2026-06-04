<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_order_items', function (Blueprint $table) {

            $table->id();

            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('product_id');

            $table->decimal('qty', 18, 4);

            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_per_unit', 18, 2)->default(0);

            $table->decimal('net_unit_price', 18, 2);
            $table->decimal('line_subtotal', 18, 2);
            $table->decimal('line_discount', 18, 2);
            $table->decimal('line_total', 18, 2);

            $table->timestamps();

            $table->foreign('sales_order_id')
                ->references('id')
                ->on('sales_orders')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
    }
};
