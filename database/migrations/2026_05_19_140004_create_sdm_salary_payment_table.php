<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_salary_payment', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('date');
            $table->unsignedBigInteger('karyawan_id');
            $table->unsignedTinyInteger('periode_bulan');
            $table->unsignedSmallInteger('periode_tahun');
            $table->unsignedBigInteger('cash_account_id');

            // Komponen dari Summary Absensi (snapshot saat dokumen dibuat)
            $table->decimal('bruto_gaji', 14, 2)->default(0);
            $table->decimal('bpjs_kes_employee', 14, 2)->default(0);
            $table->decimal('bpjs_kes_company', 14, 2)->default(0);
            $table->decimal('bpjs_tk_employee', 14, 2)->default(0);
            $table->decimal('bpjs_tk_company', 14, 2)->default(0);
            $table->decimal('pph21_amount', 14, 2)->default(0);
            $table->decimal('kasbon_potongan', 14, 2)->default(0);
            $table->decimal('nett_dibayar', 14, 2)->default(0);

            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['periode_tahun', 'periode_bulan']);
            $table->index('karyawan_id');
            $table->index('status');

            $table->foreign('karyawan_id')->references('id')->on('sdm_karyawan')->cascadeOnDelete();
            $table->foreign('cash_account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_salary_payment');
    }
};
