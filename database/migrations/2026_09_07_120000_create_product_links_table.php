<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan luar per SKU — Shopee, Tokopedia, katalog, apa pun.
 *
 * Ada karena sebagian pembeli tetap ingin membayar lewat marketplace meski sudah
 * diarahkan ke web dan WhatsApp. Tanpa tempat menyimpannya, admin menyalin
 * tautan itu dari HP-nya sendiri setiap kali ditanya, dan tautan yang salah
 * ketik baru ketahuan setelah pembeli mengeluh.
 *
 * Judulnya bebas (SKU, nama toko, apa saja) — yang mengisi yang paling tahu
 * cara membedakannya nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('judul', 120);
            $table->string('url', 500);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_links');
    }
};
