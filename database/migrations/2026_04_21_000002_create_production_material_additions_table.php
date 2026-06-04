<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_material_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('production_order_step_id')->nullable()->constrained('production_order_steps')->nullOnDelete();
            $table->string('addition_number')->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('production_material_addition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addition_id')->constrained('production_material_additions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('qty_requested', 12, 4);
            $table->string('unit')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_material_addition_items');
        Schema::dropIfExists('production_material_additions');
    }
};
