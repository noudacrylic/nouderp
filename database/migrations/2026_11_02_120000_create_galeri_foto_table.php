<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto/video di folder Galeri yang tertaut ke pesanan. Folder NAS tetap sumber kebenaran
 * berkasnya; tabel ini hanya penunjuk supaya foto produk custom bisa dicari dari chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galeri_foto', function (Blueprint $table) {
            $table->id();
            $table->string('path', 500);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sales_order_id', 'path']);
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('galeri_foto');
    }
};
