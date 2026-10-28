<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lapor Stok: karyawan divisi (Produksi, Packing) melapor barang habis / hampir habis dari
 * layar kerjanya → task Purchasing di Meja Kerja. product_id kosong = barang belum terdaftar
 * (nama_barang). task_id = task yang menangani; status laporan dibaca dari task itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lapor_stok', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('nama_barang', 150)->nullable();
            $table->enum('kondisi', ['hampir_habis', 'habis']);
            $table->string('catatan', 255)->nullable();
            $table->string('divisi', 60);
            $table->foreignId('pelapor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pelapor_nama', 100)->nullable();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamps();
            $table->index(['divisi', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lapor_stok');
    }
};
