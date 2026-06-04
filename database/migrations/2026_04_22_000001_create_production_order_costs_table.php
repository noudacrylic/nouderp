<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_order_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
            $table->foreignId('material_addition_id')->nullable()->constrained('production_material_additions')->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->foreignId('cash_account_id')->constrained('accounts');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_costs');
    }
};
