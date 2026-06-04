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

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            $table->decimal('qty_on_hand', 16, 4)->default(0);

            $table->decimal('qty_reserved_physical', 16, 4)->default(0);
            $table->decimal('qty_preorder_available', 16, 4)->default(0);
            $table->decimal('qty_reserved_preorder', 16, 4)->default(0);
            $table->decimal('qty_shipped', 16, 4)->default(0);

            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);

        });

    }

    public function down(): void
    {

        Schema::dropIfExists('product_stocks');

    }
};
