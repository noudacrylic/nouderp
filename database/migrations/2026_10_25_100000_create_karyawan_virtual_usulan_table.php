<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Karyawan Virtual tahap 2:
 * - Usulan: skill bermode "Minta persetujuan" tidak bertindak sendiri, tapi
 *   mengajukan usulan yang disetujui/ditolak manusia (Telegram atau web).
 * - Akun user virtual: dokumen yang dikerjakan karyawan virtual tercatat atas
 *   namanya. Tidak bisa login (lihat AuthController) dan tidak tampil di User & Akses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karyawan_virtual_usulan', function (Blueprint $table) {
            $table->id();
            $table->string('karyawan', 60)->index();
            $table->string('skill', 80);
            // Penanda "hal yang sama" (mis. 'bom:12') — selama masih menunggu, tidak diusulkan dua kali.
            $table->string('kunci', 120);
            $table->string('judul', 190);
            $table->text('alasan')->nullable();
            $table->json('data');                            // bekal skill untuk mengerjakan usulan
            // menunggu | diproses | disetujui | ditolak | kedaluwarsa | batal | gagal
            $table->string('status', 20)->default('menunggu');
            // nullable WAJIB: TIMESTAMP NOT NULL pertama di MariaDB diberi ON UPDATE CURRENT_TIMESTAMP
            // → tiap update usulan akan mereset batas kedaluwarsanya ke "sekarang".
            $table->timestamp('kedaluwarsa_at')->nullable();
            $table->foreignId('diputus_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diputus_at')->nullable();
            $table->string('diputus_lewat', 20)->nullable(); // web | telegram | sistem
            $table->text('catatan_hasil')->nullable();
            $table->string('dokumen', 60)->nullable();
            $table->string('tautan', 255)->nullable();
            $table->json('telegram')->nullable();            // [[chat_id, message_id], ...] untuk diedit setelah diputus
            $table->foreignId('jurnal_id')->nullable()->constrained('karyawan_virtual_jurnal')->nullOnDelete();
            $table->timestamps();

            $table->index(['skill', 'kunci', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_virtual')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_virtual');
        });
        Schema::dropIfExists('karyawan_virtual_usulan');
    }
};
