<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_attendance_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained('sdm_karyawan')->cascadeOnDelete();
            $table->date('tanggal');
            $table->enum('type', [
                'cuti',                // skip semua batch, attendance ter-record dengan status cuti (dapat full pay)
                'sakit',               // skip semua batch, attendance ter-record dengan status sakit (dapat full pay tanpa tunjangan)
                'izin_pagi',           // skip batch 08:30 → datang siang
                'izin_sore',           // skip batch 16:30 → pulang siang
                'izin_setengah_hari',  // izin setengah hari (pagi atau sore — detail di notes)
                'ganti_hari',          // ganti hari kerja (mis. masuk hari libur sebagai ganti tidak masuk hari kerja)
                'lembur',              // enable batch 20:30 (default tidak lembur)
            ]);
            $table->string('notes', 255)->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamps();

            $table->unique(['karyawan_id', 'tanggal', 'type'], 'sdm_izin_unique');
            $table->index(['tanggal', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_attendance_overrides');
    }
};
