<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_fingerprint_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('sdm_fingerprint_machines')->cascadeOnDelete();
            $table->foreignId('karyawan_id')->nullable()->constrained('sdm_karyawan')->nullOnDelete();
            $table->string('user_id_fingerprint', 50)->index()->comment('PIN/UserID dari mesin');
            $table->dateTime('scan_at');
            $table->string('verify_method', 20)->nullable()->comment('fingerprint|card|password|face');
            $table->string('verify_type', 20)->nullable()->comment('check_in|check_out|break_out|break_in|overtime_in|overtime_out|undefined');
            $table->json('raw_payload')->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('attendance_id')->nullable()->constrained('sdm_attendance')->nullOnDelete();
            $table->timestamps();

            $table->unique(['machine_id', 'user_id_fingerprint', 'scan_at'], 'sdm_fp_log_unique');
            $table->index(['scan_at', 'processed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_fingerprint_logs');
    }
};
