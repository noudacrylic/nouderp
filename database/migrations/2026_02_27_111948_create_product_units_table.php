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
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_id');

            $table->string('unit_name'); // pcs, lembar, meter
            $table->decimal('conversion_to_base', 18, 6);

            $table->integer('level')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['product_id', 'unit_name']);

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
        Schema::dropIfExists('product_units');
    }
};
