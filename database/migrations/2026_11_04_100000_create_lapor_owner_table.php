<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lapor ke Owner: laporan admin ke Meja Owner beserta balasannya. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lapor_owner', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pelapor_id')->constrained('users')->cascadeOnDelete();
            $t->text('isi');
            $t->boolean('penting')->default(false);
            $t->string('foto_path')->nullable();
            $t->text('balasan')->nullable();
            $t->foreignId('dibalas_oleh')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('dibalas_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lapor_owner');
    }
};
