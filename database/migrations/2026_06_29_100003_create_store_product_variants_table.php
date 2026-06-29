<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_product_id');
            $table->unsignedBigInteger('product_id');       // SKU ERP (products.id)
            $table->string('variant_label')->nullable();    // mis. "40x30x6"
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('store_product_id')
                ->references('id')
                ->on('store_products')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            // 1 SKU maksimal di 1 Produk Store (cegah dobel)
            $table->unique('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_variants');
    }
};
