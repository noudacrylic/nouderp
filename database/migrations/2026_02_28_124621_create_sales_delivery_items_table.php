<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_delivery_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sales_delivery_id');
            $table->unsignedBigInteger('sales_order_item_id');
            $table->unsignedBigInteger('product_id');

            $table->decimal('qty', 18, 4);

            $table->timestamps();

            $table->foreign('sales_delivery_id')
                ->references('id')
                ->on('sales_deliveries')
                ->cascadeOnDelete();

            $table->foreign('sales_order_item_id')
                ->references('id')
                ->on('sales_order_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_delivery_items');
    }
};
