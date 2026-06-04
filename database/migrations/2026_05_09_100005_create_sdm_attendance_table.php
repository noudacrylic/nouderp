<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periode_id')->constrained('sdm_periode_penggajian')->cascadeOnDelete();
            $table->foreignId('karyawan_id')->constrained('sdm_karyawan')->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('week', 5)->nullable();
            $table->string('shift_name', 50)->nullable();

            $table->time('on_work1')->nullable();
            $table->time('off_work1')->nullable();
            $table->time('on_work2')->nullable();
            $table->time('off_work2')->nullable();
            $table->time('on_work3')->nullable();
            $table->time('off_work3')->nullable();

            $table->decimal('absent_days', 4, 2)->default(0);
            $table->decimal('working_hours', 5, 2)->default(0);
            $table->decimal('ot_hours', 5, 2)->default(0);
            $table->integer('late_minutes')->default(0);
            $table->integer('early_out_minutes')->default(0);
            $table->integer('absent_punch_times')->default(0);
            $table->decimal('public_holiday_hours', 5, 2)->default(0);
            $table->decimal('leave_hours', 5, 2)->default(0);
            $table->text('remark')->nullable();

            $table->enum('status', [
                'hadir', 'terlambat', 'setengah_hari', 'pulang_awal',
                'tidak_hadir', 'libur', 'cuti', 'sakit'
            ])->default('tidak_hadir');
            $table->decimal('overtime_hours', 4, 2)->default(0);

            $table->boolean('get_full_pay')->default(false);
            $table->boolean('get_half_pay')->default(false);
            $table->boolean('get_tunjangan')->default(false);
            $table->boolean('edited_manually')->default(false);

            $table->timestamps();

            $table->unique(['periode_id', 'karyawan_id', 'tanggal'], 'sdm_att_unique');
            $table->index(['karyawan_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_attendance');
    }
};
