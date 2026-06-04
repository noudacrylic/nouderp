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
        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->decimal('qty_in', 12, 4)->default(0);
            $table->decimal('qty_out', 12, 4)->default(0);
            $table->decimal('qty_balance', 12, 4)->default(0);
            $table->decimal('unit_cost', 15, 2);
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_layers');
    }
};
