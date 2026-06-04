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
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');

            $table->decimal('qty_on_hand', 18, 4)->default(0);
            $table->decimal('qty_reserved_physical', 18, 4)->default(0);
            $table->decimal('qty_preorder_available', 18, 4)->default(0);
            $table->decimal('qty_reserved_preorder', 18, 4)->default(0);
            $table->decimal('qty_shipped', 18, 4)->default(0);

            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);

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
        Schema::dropIfExists('product_stocks');
    }
};
