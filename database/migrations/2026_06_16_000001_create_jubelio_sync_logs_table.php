<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat per-kejadian sinkronisasi Jubelio (Pesanan / Stok / Harga).
 * Diisi oleh service sinkron (push stok, push harga, sinkron pesanan) agar user
 * bisa melihat apa yang sudah/ belum tersinkron + error-nya. Bukan sumber kebenaran,
 * hanya jejak audit; dibersihkan berkala (lihat routes/console.php).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('jubelio_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);                 // order | stock | price
            $table->string('status', 16);               // success | failed | skipped
            $table->string('title');                    // label singkat (mis. nama produk / no pesanan)
            $table->string('reference')->nullable();    // SKU produk / no Sales Order Jubelio
            $table->text('message')->nullable();        // keterangan / pesan error
            $table->json('meta')->nullable();           // ekstra: delta, qty, harga, dll
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('jubelio_salesorder_id')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index('status');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jubelio_sync_logs');
    }
};
