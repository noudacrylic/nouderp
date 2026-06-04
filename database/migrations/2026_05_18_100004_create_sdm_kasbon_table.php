<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_kasbon', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->foreignId('karyawan_id')->constrained('sdm_karyawan')->restrictOnDelete();
            $table->date('tanggal_pengajuan');
            $table->decimal('jumlah_pinjaman', 15, 2)->default(0);
            $table->decimal('cicilan_per_bulan', 15, 2)->default(0);
            $table->date('tanggal_mulai_potong')->nullable();

            // Akun kas/bank untuk pencairan (saat posting)
            $table->unsignedBigInteger('cash_account_id')->nullable();
            $table->foreign('cash_account_id')->references('id')->on('accounts')->nullOnDelete();

            $table->decimal('sisa_terhutang', 15, 2)->default(0);

            $table->enum('status', ['draft', 'posted', 'lunas', 'void'])->default('draft');

            $table->unsignedBigInteger('journal_id')->nullable();
            $table->foreign('journal_id')->references('id')->on('journals')->nullOnDelete();

            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['karyawan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_kasbon');
    }
};
