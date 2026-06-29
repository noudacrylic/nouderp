<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_category_id')->nullable();
            $table->string('slug')->unique();
            $table->string('name');                       // nama tampil web
            $table->longText('description')->nullable();   // deskripsi panjang
            $table->string('short_description')->nullable();
            $table->string('meta_title')->nullable();      // SEO
            $table->string('meta_description')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('store_category_id')
                ->references('id')
                ->on('store_categories')
                ->nullOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_products');
    }
};
