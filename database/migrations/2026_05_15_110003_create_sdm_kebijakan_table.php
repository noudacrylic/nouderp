<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_kebijakan', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->text('deskripsi')->nullable();

            // Rule engine sederhana berbasis waktu absensi.
            // Trigger: kondisi yang harus terpenuhi.
            $table->enum('trigger_field', [
                'late_minutes',        // menit terlambat (scan_in vs jam jadwal)
                'early_out_minutes',   // menit pulang awal (scan_out vs jam jadwal)
                'absent_days_month',   // hari absen tanpa izin dalam 1 bulan
                'overtime_hours_day',  // jam lembur dalam 1 hari
                'scan_in_before',      // scan masuk SEBELUM jam X (untuk bonus absen)
            ]);
            $table->enum('operator', ['>', '>=', '<', '<=', '=']);
            $table->string('trigger_value', 50)->comment('Nilai banding (mis. 10 untuk menit, "08:00" untuk jam)');

            // Aksi: efek pada kalkulasi gaji harian.
            $table->enum('action', [
                'no_tunjangan_harian',
                'no_bonus_absen',
                'no_full_pay',
                'half_pay',
                'flag_sp_pertimbangan',
                'add_bonus_absen',
            ]);
            $table->decimal('action_amount', 15, 2)->nullable()->comment('Untuk action yang punya nominal, mis. add_bonus_absen');

            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->integer('priority')->default(100)->comment('Urutan evaluasi, kecil = duluan');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_kebijakan');
    }
};
