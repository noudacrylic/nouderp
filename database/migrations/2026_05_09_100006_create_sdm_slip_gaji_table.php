<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_slip_gaji', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periode_id')->constrained('sdm_periode_penggajian')->cascadeOnDelete();
            $table->foreignId('karyawan_id')->constrained('sdm_karyawan')->cascadeOnDelete();
            $table->string('code', 50)->unique();

            $table->tinyInteger('hari_hadir_penuh')->default(0);
            $table->tinyInteger('hari_setengah')->default(0);
            $table->tinyInteger('hari_terlambat')->default(0);
            $table->tinyInteger('hari_pulang_awal')->default(0);
            $table->tinyInteger('hari_tidak_hadir')->default(0);
            $table->tinyInteger('hari_libur')->default(0);
            $table->decimal('total_jam_lembur', 5, 2)->default(0);

            $table->decimal('gaji_pokok_bulan', 15, 2)->default(0);
            $table->decimal('gaji_per_hari', 15, 2)->default(0);
            $table->decimal('gaji_pokok_dibayar', 15, 2)->default(0);
            $table->decimal('lembur_amount', 15, 2)->default(0);
            $table->decimal('tunjangan_harian_amount', 15, 2)->default(0);
            $table->decimal('tunjangan_bulanan_amount', 15, 2)->default(0);
            $table->decimal('bonus', 15, 2)->default(0);
            $table->decimal('thr_amount', 15, 2)->default(0);
            $table->decimal('cuti_unused_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('kasbon_payment', 15, 2)->default(0);
            $table->decimal('total_gaji', 15, 2)->default(0);

            $table->enum('status', ['draft', 'finalized'])->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['periode_id', 'karyawan_id'], 'sdm_slip_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_slip_gaji');
    }
};
