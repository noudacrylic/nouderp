<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kotak Porto: foto/video produk yang diunggah dari HP (menu Porto di PWA) dan ditampung
 * sementara di disk server — utuh, tanpa kompresi — sampai dilampirkan ke kartu Meja Kerja
 * dari laptop. Saat dilampirkan berkasnya dipindah ke NAS (folder porto) lalu baris ini
 * ditandai dipakai. Yang tak pernah dipakai dibersihkan galeri:sapu-kotak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galeri_kotak', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('nama', 255);
            $t->string('berkas', 255);      // relatif terhadap config galeri.kotak_dir
            $t->string('jenis', 10);        // gambar | video
            $t->unsignedBigInteger('ukuran')->default(0);
            $t->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $t->string('path_nas', 500)->nullable();
            $t->timestamp('dipakai_at')->nullable();
            $t->timestamps();
            $t->index(['dipakai_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('galeri_kotak');
    }
};
