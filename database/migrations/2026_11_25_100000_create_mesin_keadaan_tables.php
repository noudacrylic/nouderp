<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sinyal mesin CNC (25 Nov 2026): server ERP (satu LAN dengan mesin) membaca status pengontrol
 * laser lewat jaringan dan mencatat potongan-potongan keadaan mesin: memotong / diam / bergerak
 * tanpa memotong / tak terjangkau. Dipakai Penilaian Operator CNC (waktu tanggap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_department_executors', function (Blueprint $t) {
            $t->string('mesin_ip', 45)->nullable()->after('kepala_ganda');
            // udp = Ruida standar (UDP 50200 + checksum); tcp = seri kartu 0x90109010 (TCP 50200).
            $t->string('mesin_protokol', 10)->nullable()->after('mesin_ip');
        });

        Schema::create('production_mesin_keadaan', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('executor_id');
            $t->string('keadaan', 10);                 // potong | diam | gerak | putus
            $t->unsignedBigInteger('kode')->nullable(); // nilai status mentah pengontrol
            $t->dateTime('mulai_at');
            $t->dateTime('sampai_at')->nullable();      // null = masih berlangsung
            $t->dateTime('dilihat_at')->nullable();     // pembacaan terakhir potongan ini
            $t->timestamps();
            $t->index(['executor_id', 'mulai_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_mesin_keadaan');
        Schema::table('production_department_executors', function (Blueprint $t) {
            $t->dropColumn(['mesin_ip', 'mesin_protokol']);
        });
    }
};
