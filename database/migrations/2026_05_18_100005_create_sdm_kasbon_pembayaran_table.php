<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_kasbon_pembayaran', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->foreignId('kasbon_id')->constrained('sdm_kasbon')->restrictOnDelete();
            $table->date('tanggal_bayar');
            $table->decimal('jumlah', 15, 2)->default(0);

            // 'manual' = karyawan bayar tunai/transfer, 'gaji' = dipotong dari slip
            $table->enum('source', ['manual', 'gaji'])->default('manual');

            $table->unsignedBigInteger('slip_gaji_id')->nullable();
            $table->foreign('slip_gaji_id')->references('id')->on('sdm_slip_gaji')->nullOnDelete();

            // Untuk source=manual: akun kas/bank yang menerima
            $table->unsignedBigInteger('cash_account_id')->nullable();
            $table->foreign('cash_account_id')->references('id')->on('accounts')->nullOnDelete();

            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');

            $table->unsignedBigInteger('journal_id')->nullable();
            $table->foreign('journal_id')->references('id')->on('journals')->nullOnDelete();

            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['kasbon_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_kasbon_pembayaran');
    }
};
