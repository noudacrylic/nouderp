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

        Schema::create('stock_layers', function (Blueprint $table) {

            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            $table->string('source_type');

            $table->unsignedBigInteger('source_id')->nullable();

            $table->decimal('qty_in', 16, 4);

            $table->decimal('qty_remaining', 16, 4);

            $table->decimal('unit_cost', 16, 2);

            $table->timestamps();

        });

    }

    public function down(): void
    {

        Schema::dropIfExists('stock_layers');

    }
};
