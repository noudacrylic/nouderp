<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_sales_manual', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->tinyInteger('month'); // 1-12
            $table->decimal('qty', 18, 4);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sales_manual');
    }
};
