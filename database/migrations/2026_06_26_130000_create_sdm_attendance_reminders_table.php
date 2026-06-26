<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak pengingat absensi yang sudah dikirim (anti-spam). Satu karyawan hanya
 * menerima tiap jenis pengingat sekali per hari. Lihat AttendanceReminderService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sdm_attendance_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained('sdm_karyawan')->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('type', 20); // before_in | late_in | before_out
            $table->timestamps();

            $table->unique(['karyawan_id', 'tanggal', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_attendance_reminders');
    }
};
