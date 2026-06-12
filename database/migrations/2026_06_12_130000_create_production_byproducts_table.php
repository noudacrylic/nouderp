<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master daftar produk yang boleh menjadi produk sampingan + persentase biaya
        // PER UNIT (basis 1 siklus produksi). Dipakai BOM/OP untuk membatasi pilihan
        // output sampingan & mengisi persentase otomatis.
        Schema::create('production_byproducts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->decimal('percentage', 8, 4)->default(0); // % biaya per unit (per 1 siklus)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_byproducts');
    }
};
