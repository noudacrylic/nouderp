<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Asumsi waktu per unit pindah rumah: dari halaman Kuota ke halaman Waktu Produksi.
 *
 * Namanya ikut dibetulkan. Tabel ini tidak pernah bicara soal kuota — isinya waktu per unit,
 * yang di model HPP adalah PEMBILANG. Kuota mengandaikan penyebutnya (jam per slot). Membiarkan
 * namanya "quota" akan membuat dua tuas yang sengaja dipisah itu terlihat seperti satu hal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('production_quota_assumptions', 'production_time_assumptions');
    }

    public function down(): void
    {
        Schema::rename('production_time_assumptions', 'production_quota_assumptions');
    }
};
