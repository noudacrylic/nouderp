<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_payroll_setting', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expense_salary_account_id')->nullable();
            $table->unsignedBigInteger('bpjs_kesehatan_liability_account_id')->nullable();
            $table->unsignedBigInteger('bpjs_tk_liability_account_id')->nullable();
            $table->unsignedBigInteger('pph21_liability_account_id')->nullable();
            $table->unsignedBigInteger('kasbon_receivable_account_id')->nullable();
            $table->timestamps();

            $table->foreign('expense_salary_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('bpjs_kesehatan_liability_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('bpjs_tk_liability_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('pph21_liability_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('kasbon_receivable_account_id')->references('id')->on('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_payroll_setting');
    }
};
