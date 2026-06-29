<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_product_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_product_id');
            $table->enum('kind', ['image', 'video'])->default('image');
            $table->enum('source', ['r2', 'youtube'])->default('r2');
            $table->string('url');                       // URL R2 atau link/ID YouTube
            $table->string('r2_key')->nullable();        // key objek R2 (utk garbage collector)
            $table->string('alt_text')->nullable();      // SEO / aksesibilitas
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();                       // foto lama menunggu di-GC (>7 hari)

            $table->foreign('store_product_id')
                ->references('id')
                ->on('store_products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_media');
    }
};
