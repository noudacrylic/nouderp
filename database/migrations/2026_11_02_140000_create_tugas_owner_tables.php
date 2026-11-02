<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tugas dari Owner ke admin: brief (video/suara/foto), admin menulis ulang yang ia tangkap,
 * dikerjakan, lalu diperiksa owner. Berkasnya di NAS; tabel ini hanya penunjuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tugas_owner', function (Blueprint $table) {
            $table->id();
            $table->string('judul', 200);
            $table->string('jenis', 30)->default('lain');
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->foreignId('pengerja_id')->constrained('users');
            $table->date('tenggat')->nullable();
            $table->boolean('wajib_periksa')->default(true);
            $table->string('tahap', 20)->default('draf');
            $table->unsignedInteger('jumlah_revisi')->default(0);
            $table->timestamp('selesai_at')->nullable();
            $table->timestamps();
            $table->index(['pengerja_id', 'tahap']);
            $table->index(['dibuat_oleh', 'tahap']);
        });

        Schema::create('tugas_owner_pesan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tugas_id')->constrained('tugas_owner')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('jenis', 20);
            $table->text('isi')->nullable();
            $table->timestamps();
            $table->index(['tugas_id', 'id']);
        });

        Schema::create('tugas_owner_lampiran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tugas_id')->constrained('tugas_owner')->cascadeOnDelete();
            $table->foreignId('pesan_id')->nullable()->constrained('tugas_owner_pesan')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('path', 500);
            $table->string('nama', 255);
            $table->string('jenis', 10);
            $table->unsignedBigInteger('ukuran')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tugas_owner_lampiran');
        Schema::dropIfExists('tugas_owner_pesan');
        Schema::dropIfExists('tugas_owner');
    }
};
