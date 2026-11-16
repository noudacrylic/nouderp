<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Info foto satu pesanan (karya): diisi Admin Desain saat memotret. Bahan skill Galeri web
 * (layak tayang, nama instansi, foto utama untuk "Sudah Dipercaya Oleh") dan tempat folder
 * `<Lini>/porto/<SO + instansi>` di NAS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galeri_karya', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_order_id')->unique()->constrained('sales_orders')->cascadeOnDelete();
            $t->string('lini', 150);
            $t->string('folder', 500);
            $t->boolean('layak_galeri')->default(false);
            $t->string('nama_instansi', 150)->nullable();
            $t->string('jenis_produk', 100)->nullable();
            $t->string('foto_utama', 500)->nullable();
            $t->text('catatan')->nullable();
            // Kartu ditutup tanpa foto (barang sudah dikirim / kamera bermasalah).
            $t->string('tanpa_foto_alasan', 150)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('galeri_karya');
    }
};
