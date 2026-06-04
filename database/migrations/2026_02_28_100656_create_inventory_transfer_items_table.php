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
        Schema::create('inventory_transfer_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('product_id');

            $table->decimal('qty', 18, 4);

            $table->timestamps();

            $table->foreign('transfer_id')
                ->references('id')->on('inventory_transfers')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')->on('products');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
    }
};
