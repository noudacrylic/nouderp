<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan izin oleh karyawan via PWA (alur draft→posting).
 *
 * pending  = draft, NOL efek payroll.
 * approved = posting: AttendanceOverride dibuat (efek payroll di sini).
 * rejected = ditolak, tanpa efek.
 *
 * Lihat memori project_dashboard_karyawan_2026_06_23.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_izin_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained('sdm_karyawan')->cascadeOnDelete();

            // Tipe: semua AttendanceOverride::TYPES + 'toleransi'.
            $table->string('type', 32);

            // Tanggal (cuti & sakit boleh rentang → tanggal_akhir).
            $table->date('tanggal');
            $table->date('tanggal_akhir')->nullable();

            // Field khusus per tipe (mirror AttendanceOverride).
            $table->date('paired_date')->nullable();
            $table->enum('sesi', ['pagi', 'sore'])->nullable();
            $table->enum('paired_sesi', ['pagi', 'sore'])->nullable();
            $table->time('jam_masuk_override')->nullable();
            $table->time('jam_pulang_override')->nullable();

            $table->text('alasan');
            $table->string('lampiran_path')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_notes')->nullable();

            // ID AttendanceOverride yang dibuat saat approve (untuk batal/void).
            $table->json('override_ids')->nullable();

            $table->timestamps();

            $table->index(['karyawan_id', 'status']);
            $table->index(['status', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_izin_requests');
    }
};
