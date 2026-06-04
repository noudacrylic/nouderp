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
        Schema::create('product_bundles', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('bundle_product_id');
            $table->unsignedBigInteger('component_product_id');

            $table->decimal('qty_required', 18, 4);

            $table->timestamps();

            $table->foreign('bundle_product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->foreign('component_product_id')
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
        Schema::dropIfExists('product_bundles');
    }
};
