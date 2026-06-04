<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique(); // prefix: PO
            $table->enum('type', ['ready_stock', 'custom', 'repair']);
            $table->foreignId('bom_id')->nullable()->constrained('boms')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->decimal('planned_cycles', 10, 4)->default(1); // jumlah siklus
            $table->decimal('planned_qty', 18, 4)->default(0);    // qty target output
            $table->date('production_date');
            $table->date('target_completion_date')->nullable();
            $table->enum('status', ['draft', 'confirmed', 'in_progress', 'completed', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
