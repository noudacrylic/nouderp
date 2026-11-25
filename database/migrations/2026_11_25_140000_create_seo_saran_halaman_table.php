<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan "tampil tapi tak diklik": halaman noudakrilik.com yang sering muncul di Google (posisi
 * bagus) tapi jarang diklik, beserta usulan judul & deskripsi dari AI. Halaman produk bisa
 * langsung dipakai (store_products.meta_title/meta_description).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_saran_halaman', function (Blueprint $t) {
            $t->id();
            $t->string('url', 500)->unique();
            $t->string('jenis', 10);                    // produk | kategori | beranda | lain
            $t->foreignId('store_product_id')->nullable()->constrained('store_products')->nullOnDelete();
            $t->unsignedInteger('tayang')->default(0);
            $t->unsignedInteger('klik')->default(0);
            $t->decimal('posisi', 6, 2)->nullable();
            $t->json('kueri')->nullable();              // [{kata, tayang, klik, posisi}]
            $t->string('judul_sekarang', 300)->nullable();
            $t->string('deskripsi_sekarang', 500)->nullable();
            $t->string('judul_usulan', 300)->nullable();
            $t->string('deskripsi_usulan', 500)->nullable();
            $t->string('alasan', 500)->nullable();
            $t->string('status', 10)->default('baru');  // baru | dipakai | diabaikan
            $t->timestamp('dipakai_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_saran_halaman');
    }
};
