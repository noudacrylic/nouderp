<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog/Artikel SEO etalase (Fase 3). ERP = sumber kebenaran; etalase render + cache.
 * Kategori artikel opsional (nullOnDelete → artikel tetap ada, kategori kosong).
 * Penjadwalan terbit via published_at: artikel tampil bila status=published & published_at<=now.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_article_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('store_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_article_category_id')->nullable()
                ->constrained('store_article_categories')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable(); // HTML dari editor WYSIWYG
            $table->string('cover_url')->nullable();
            $table->string('cover_key')->nullable();  // key di disk media (R2/lokal) untuk hapus
            $table->string('author')->nullable();
            $table->string('status')->default('draft'); // draft | published
            $table->timestamp('published_at')->nullable(); // jadwal terbit
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_articles');
        Schema::dropIfExists('store_article_categories');
    }
};
