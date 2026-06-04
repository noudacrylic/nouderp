<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_karyawan_schedule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained('sdm_karyawan')->cascadeOnDelete();
            $table->tinyInteger('day_of_week')->comment('0=Minggu, 1=Senin, ..., 6=Sabtu (PHP date("w"))');
            $table->time('jam_masuk')->nullable();
            $table->time('jam_pulang')->nullable();
            $table->time('jam_istirahat_start')->nullable();
            $table->time('jam_istirahat_end')->nullable();
            $table->boolean('is_off')->default(false)->comment('Libur (Minggu, dsb)');
            $table->timestamps();

            $table->unique(['karyawan_id', 'day_of_week'], 'sdm_kar_sched_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_karyawan_schedule');
    }
};
