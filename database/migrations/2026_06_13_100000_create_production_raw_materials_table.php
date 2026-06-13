<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master daftar produk bahan baku (lembaran) + ukuran lembar (Panjang × Lebar).
        // Dipakai Kalkulator Produk Custom di OP untuk menghitung kebutuhan bahan baku.
        Schema::create('production_raw_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->decimal('panjang', 10, 2); // panjang lembar
            $table->decimal('lebar', 10, 2);   // lebar lembar
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_raw_materials');
    }
};
