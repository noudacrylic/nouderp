<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daftar tautan marketplace yang BERDIRI SENDIRI — nama & alamatnya diketik
 * manual, tidak menempel ke SKU mana pun.
 *
 * Sengaja lepas dari `product_links`: yang dijual di lapak sering bukan satu
 * SKU gudang (paket bundling, listing lama yang namanya sudah terlanjur
 * dikenal pembeli, barang titipan). Memaksanya menempel ke SKU berarti admin
 * harus mengarang produk ERP hanya supaya punya tempat menyimpan sebuah
 * alamat — dan produk karangan itu ikut bocor ke laporan stok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_marketplace_links', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 160);
            $table->string('url', 500);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['urutan', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_marketplace_links');
    }
};
