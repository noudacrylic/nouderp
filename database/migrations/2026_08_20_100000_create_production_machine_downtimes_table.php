<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Henti mesin: rentang waktu sebuah eksekutor tidak bisa dipakai produksi karena perawatan,
 * kerusakan, mati listrik, dan sejenisnya.
 *
 * Tanpa ini, jam perawatan tidak bisa dibedakan dari mesin yang menganggur karena tidak ada
 * pekerjaan — padahal keduanya menuntut keputusan yang berbeda. Yang satu biaya wajar dari
 * memiliki mesin, yang lain kapasitas yang benar-benar terbuang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_machine_downtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('executor_id')->constrained('production_department_executors')->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->string('reason', 30)->default('perawatan');
            $table->text('notes')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamps();

            // Kalender selalu bertanya "apa yang terjadi pada tanggal X", jadi indeksnya per waktu.
            $table->index(['started_at', 'ended_at']);
            $table->index(['executor_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_machine_downtimes');
    }
};
