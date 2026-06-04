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
        Schema::create('sales_quotation_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('quotation_id')
                ->constrained('sales_quotations')
                ->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()
                ->constrained('products')
                ->nullOnDelete();

            $table->text('description')->nullable();

            $table->enum('item_type', ['product', 'service'])
                ->default('product');

            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_quotation_items');
    }
};
