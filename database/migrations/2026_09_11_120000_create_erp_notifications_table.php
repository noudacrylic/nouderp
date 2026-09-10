<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi yang tinggal DI DALAM ERP — bisa dilihat begitu layar dibuka.
 *
 * Berdampingan dengan web push, bukan menggantikannya, karena keduanya menutupi
 * kekurangan masing-masing. Web push sampai walau ERP tidak dibuka, tapi ia
 * menuntut izin peramban, mati kalau peramannya ditutup, dan sekali lewat ia
 * hilang selamanya. Baris di tabel ini tidak menuntut izin apa pun dan tidak
 * pernah hilang sampai dibaca — yang justru penting untuk operan chat, karena
 * pekerjaan yang berpindah tangan tidak boleh bergantung pada apakah orangnya
 * kebetulan sedang membuka peramban saat itu.
 *
 * Satu baris PER PENERIMA, bukan satu baris yang dibagi banyak orang. Lebih
 * boros, tapi tiap orang menandai bacaannya sendiri — dan tanpa itu "sudah
 * dibaca" milik satu orang akan menghapus tanda milik semua orang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Golongan peristiwa: chat_masuk | chat_dioper | pesanan_instant | …
            $table->string('jenis', 40);

            $table->string('judul', 160);
            $table->string('isi', 500);

            // Ke mana orangnya dibawa saat diklik. Kosong = tidak ke mana-mana.
            $table->string('url', 500)->nullable();

            /*
             * Penanda peristiwa, sama dengan `tag` web push. Dipakai MERINGKAS:
             * lima pesan beruntun dari satu pelanggan menjadi satu baris yang
             * diperbarui, bukan lima baris yang membuat daftarnya tak terbaca.
             */
            $table->string('tag', 120)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Dua kueri yang benar-benar dipakai: hitung belum dibaca, dan
            // daftar terbaru milik satu orang.
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_notifications');
    }
};
