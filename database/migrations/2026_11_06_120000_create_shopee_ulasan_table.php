<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salinan ulasan pembeli Shopee (product/get_comment) — bahan skill "Balas ulasan jelek"
 * di Meja Kerja. Draf balasan & draf chat dari AI disimpan di baris yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_ulasan', function (Blueprint $t) {
            $t->id();
            $t->string('env', 10);
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('comment_id');
            $t->string('order_sn', 40)->index();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->unsignedBigInteger('model_id')->nullable();
            $t->string('buyer_username', 100)->nullable();
            $t->unsignedTinyInteger('rating_star');
            $t->text('comment')->nullable();
            $t->json('media')->nullable();
            // EDITABLE = pembeli masih bisa mengubah ulasannya (sekali); HAVE_EDITED_ONCE = tidak lagi.
            $t->string('editable', 30)->nullable();
            $t->boolean('hidden')->default(false);
            $t->text('balasan')->nullable();
            // Tanggal bisnis: nullable supaya MariaDB tak memberi ON UPDATE CURRENT_TIMESTAMP.
            $t->timestamp('dibalas_at')->nullable();
            $t->foreignId('dibalas_oleh')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('dibuat_shopee_at')->nullable();
            $t->text('draf_balasan')->nullable();
            $t->text('draf_chat')->nullable();
            $t->timestamp('draf_at')->nullable();
            $t->string('draf_galat', 255)->nullable();
            $t->json('raw')->nullable();
            $t->timestamp('disinkron_at')->nullable();
            $t->timestamps();

            $t->unique(['env', 'shop_id', 'comment_id']);
            $t->index(['rating_star', 'dibalas_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_ulasan');
    }
};
