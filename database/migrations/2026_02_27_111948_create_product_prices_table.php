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
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_id');

            $table->string('channel')->default('default');
            // default
            // b2b
            // marketplace_shopee
            // marketplace_tokopedia

            $table->decimal('price', 18, 2);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['product_id', 'channel']);

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
