<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_sp_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained('sdm_karyawan')->cascadeOnDelete();
            $table->enum('sanksi', ['SP1', 'SP2', 'SP3']);
            $table->date('tanggal_terbit');
            $table->date('berlaku_sampai')->nullable()->comment('Pasal 8: SP berlaku 6 bulan');
            $table->text('alasan');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->comment('false jika sudah dicabut sebelum berlaku_sampai');
            $table->timestamps();

            $table->index(['karyawan_id', 'is_active']);
            $table->index(['berlaku_sampai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_sp_history');
    }
};
