<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bundle_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bundle_product_id');
            $table->unsignedBigInteger('component_product_id');
            $table->decimal('qty', 18, 4);
            $table->timestamps();

            $table->foreign('bundle_product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('component_product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_components');
    }
};
