<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Karyawan Virtual: otomasi ERP yang dibungkus jadi "karyawan" dengan skill.
 * Profil & daftar skill hidup di config/karyawan_virtual.php (skill = kode);
 * yang disimpan di sini hanya keadaannya: mode tiap skill dan jurnal kerja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karyawan_virtual_skills', function (Blueprint $table) {
            $table->id();
            $table->string('skill', 80)->unique();          // mis. 'produksi.op-stok-menipis'
            $table->string('mode', 20);                     // nonaktif | bayangan | persetujuan | otomatis
            $table->foreignId('mode_diubah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mode_diubah_at')->nullable();
            // Hasil pemeriksaan terakhir — termasuk yang DILEWATI, supaya "kenapa tidak dibuat"
            // bisa dijawab tanpa menulis jurnal tiap 30 menit.
            $table->timestamp('terakhir_jalan_at')->nullable();
            $table->string('ringkasan_terakhir', 255)->nullable();
            $table->json('rincian_terakhir')->nullable();
            $table->timestamps();
        });

        Schema::create('karyawan_virtual_jurnal', function (Blueprint $table) {
            $table->id();
            $table->string('karyawan', 60)->index();
            $table->string('skill', 80)->index();
            $table->string('mode', 20);
            $table->string('pemicu', 20);                   // jadwal | manual | peristiwa
            $table->string('keterangan_pemicu', 190)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ringkasan', 255);
            $table->unsignedInteger('jumlah_tindakan')->default(0);
            $table->unsignedInteger('jumlah_diperiksa')->default(0);
            $table->json('rincian');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karyawan_virtual_jurnal');
        Schema::dropIfExists('karyawan_virtual_skills');
    }
};
