<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asumsi harga bahan baku — "kalau akrilik 2 mm jadi Rp360.000, HPP saya jadi berapa".
 *
 * Satu set asumsi aktif (bukan skenario bernama): isinya diketik, dibaca, lalu dikosongkan
 * saat sudah tidak dipakai. Angka di sini TIDAK PERNAH menyentuh persediaan maupun jurnal —
 * hanya dipakai halaman analisa saat mode asumsi dinyalakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_price_assumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_price_assumptions');
    }
};
