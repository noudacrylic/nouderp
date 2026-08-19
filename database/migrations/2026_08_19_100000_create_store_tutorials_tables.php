<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tutorial pemasangan — halaman video + langkah bergambar yang dituju QR pada
 * stiker produk.
 *
 * Entitas sendiri, bukan kolom di produk, karena hubungannya banyak-ke-banyak:
 * satu video "tempat brosur" melayani beberapa produk tempat brosur sekaligus,
 * satu produk bisa punya beberapa tutorial (pasang, rawat), dan sebagian
 * tutorial tak dimiliki produk mana pun ("cara membersihkan akrilik").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_tutorials', function (Blueprint $t) {
            $t->id();

            /*
             * Kode yang TERCETAK di stiker produk (mis. "tb1" = tempat brosur 1),
             * dipakai sebagai alamat pendek /t/{code}.
             *
             * SEKALI TERCETAK, TIDAK BOLEH BERUBAH — stiker sudah menempel di
             * barang yang beredar dan tak bisa ditarik. Justru karena itu kode
             * ini dipisah dari `slug`: judul & slug boleh diganti kapan saja,
             * video boleh diganti, produk boleh berganti nama; kode tetap.
             *
             * Collation MySQL bawaan tidak peduli besar-kecil huruf, jadi QR
             * yang disandikan huruf besar (mode alfanumerik — QR-nya satu
             * tingkat lebih kecil) tetap ketemu.
             */
            $t->string('code', 16)->unique();

            $t->string('slug')->unique();          // alamat kanonis /tutorial/{slug}
            $t->string('title');

            // ID video, bukan URL penuh — dari sini diturunkan gambar sampul,
            // alamat embed, dan data terstruktur VideoObject sekaligus.
            $t->string('youtube_id', 32)->nullable();

            // Ringkas: tampil di bawah video, sekaligus jadi meta description.
            $t->text('description')->nullable();

            // HTML dari editor Trix: langkah bergambar. Video tutorial Noud tanpa
            // narasi, jadi bagian INILAH satu-satunya yang bisa dibaca Google.
            $t->longText('content')->nullable();

            $t->string('status', 16)->default('draft');

            /*
             * Dua penghitung yang sengaja dipisah:
             *   scan_count — datang lewat /t/{code}, artinya dari stiker fisik.
             *                Ini pengganti langsung statistik bit.ly yang dilepas.
             *   view_count — total kunjungan halaman, termasuk dari Google & menu.
             * Digabung, keduanya kehilangan makna masing-masing.
             */
            $t->unsignedInteger('scan_count')->default(0);
            $t->unsignedInteger('view_count')->default(0);

            $t->integer('sort_order')->default(0);
            $t->string('meta_title')->nullable();
            $t->string('meta_description')->nullable();
            $t->timestamps();

            $t->index(['status', 'sort_order']);
        });

        Schema::create('store_tutorial_product', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_tutorial_id')->constrained('store_tutorials')->cascadeOnDelete();
            $t->foreignId('store_product_id')->constrained('store_products')->cascadeOnDelete();
            $t->integer('sort_order')->default(0);
            $t->unique(['store_tutorial_id', 'store_product_id'], 'store_tutorial_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_tutorial_product');
        Schema::dropIfExists('store_tutorials');
    }
};
