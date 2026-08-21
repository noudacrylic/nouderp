<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya packing per unit yang ditetapkan sendiri untuk sebuah produk.
 *
 * Halaman Fixed Cost menghasilkan SATU angka packing rata-rata untuk semua produk
 * (total biaya packing ÷ jumlah surat jalan). Kenyataannya jauh lebih beragam: ada
 * produk yang cukup dus biasa, ada yang butuh kardus khusus, ada yang harus dipeti
 * kayu. Baris di tabel ini menimpa angka rata-rata itu untuk produk ybs.
 *
 * Produk yang tidak punya baris di sini memakai angka dari Fixed Cost — jadi tabel ini
 * hanya berisi PENGECUALIAN, bukan salinan seluruh produk yang harus dijaga sinkron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_product_packing_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->decimal('amount_per_unit', 15, 2)->default(0);
            $table->string('notes', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_product_packing_costs');
    }
};
