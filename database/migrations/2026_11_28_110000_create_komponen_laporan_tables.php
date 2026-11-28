<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan komponen kurang/rusak (owner 28 Nov 2026): Assembling memilih Pending "Komponen
 * kurang / rusak" + penyebab + daftar komponen (nama, jumlah, foto). Selain "bahan beli belum
 * ada", laporan jadi permintaan POTONG SUSULAN untuk CNC; saat selesai CNC memilih sumber bahan
 * (stok → penambahan bahan, sisa → tidak). Data mutu CNC & Assembling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_komponen_laporan', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('production_order_id')->index();
            $t->unsignedBigInteger('production_order_step_id')->index(); // langkah yang di-Pending
            $t->unsignedBigInteger('time_log_id')->nullable();
            $t->string('penyebab', 20);            // belum_dipotong | salah_potong | rusak_rakit | bahan_beli
            $t->string('status', 16)->default('menunggu')->index(); // menunggu | selesai | tanpa_susulan
            $t->unsignedBigInteger('dilaporkan_by')->nullable();
            $t->dateTime('susulan_selesai_at')->nullable();
            $t->unsignedBigInteger('susulan_by')->nullable();
            $t->string('sumber_bahan', 10)->nullable(); // stok | sisa
            $t->unsignedBigInteger('material_addition_id')->nullable();
            $t->string('putusan', 12)->nullable();      // untuk penilaian mutu nanti
            $t->timestamps();
        });

        Schema::create('production_komponen_laporan_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laporan_id')->index();
            $t->string('nama', 120);
            $t->unsignedInteger('jumlah');
            $t->string('foto_path')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_komponen_laporan_items');
        Schema::dropIfExists('production_komponen_laporan');
    }
};
