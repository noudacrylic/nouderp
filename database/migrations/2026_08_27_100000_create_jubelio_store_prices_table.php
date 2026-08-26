<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekaman harga yang SEDANG DIPEGANG JUBELIO untuk tiap toko — kebalikan arah dari tombol
 * "Kirim" di Analisa ▸ Harga Produk.
 *
 * Gunanya menjawab satu pertanyaan yang selama ini tidak bisa dijawab dari dalam ERP:
 * "harga yang saya kirim kemarin benar-benar mendarat, atau diam-diam masih harga lama?"
 * `pushed_at` hanya mencatat bahwa kita PERNAH mengirim; ia tidak tahu apa yang terjadi
 * sesudahnya — harga bisa diubah orang lain langsung di Jubelio atau di seller center.
 *
 * ── KENAPA TABEL SENDIRI, BUKAN KOLOM DI `product_channel_prices` ─────────
 *
 * Karena `product_channel_prices` terdaftar di `AnalysisCache::TABLES`. Menempelkan harga
 * marketplace di sana membuat SETIAP penarikan harga menggeser sidik jari data, dan itu
 * membuang seluruh simpanan Analisa — HPP ikut dihitung ulang dari nol hanya karena kita
 * menanyakan harga ke Jubelio. Kalau penarikannya nanti dijadwalkan rutin, halaman Analisa
 * tidak akan pernah sempat hangat.
 *
 * Tabel ini SENGAJA TIDAK didaftarkan di `TABLES`, dan itu aman justru karena angkanya
 * tidak menyuapi satu pun perhitungan: ia dibaca langsung di controller sesudah
 * ChannelPricingService::rows() selesai, jadi selalu tampil apa adanya.
 *
 * Butirannya per TOKO, bukan per kanal: satu kanal bisa punya lebih dari satu toko (TikTok
 * & Tokopedia), dan keduanya bisa saja berharga berbeda. Meratakannya lebih dulu akan
 * menyembunyikan justru selisih yang paling perlu dilihat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jubelio_store_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->index();

            // Null = sudah ditanya, tapi Jubelio tidak memberi angka untuk toko ini.
            // Bedakan dari "belum pernah ditanya" (barisnya memang tidak ada) — yang
            // pertama perlu ditampilkan sebagai keterangan, yang kedua tidak.
            $table->decimal('price', 18, 2)->nullable();
            $table->timestamp('fetched_at')->nullable();

            // Alasan bila harganya tidak didapat, apa adanya dari Jubelio. Disimpan supaya
            // "—" di layar punya sebab yang bisa dibaca, bukan kegagalan tanpa nama.
            $table->string('note', 255)->nullable();

            $table->timestamps();
            $table->unique(['product_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jubelio_store_prices');
    }
};
