<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_payroll_setting', function (Blueprint $table) {
            $table->unsignedBigInteger('thr_expense_account_id')->nullable()->after('expense_salary_account_id');
            $table->unsignedBigInteger('bpjs_company_expense_account_id')->nullable()->after('thr_expense_account_id');

            $table->decimal('bpjs_kesehatan_employee_rate', 5, 2)->default(1.00)->after('bpjs_kesehatan_liability_account_id');
            $table->decimal('bpjs_kesehatan_company_rate', 5, 2)->default(4.00)->after('bpjs_kesehatan_employee_rate');
            $table->decimal('bpjs_kesehatan_max_base', 14, 2)->default(12000000)->after('bpjs_kesehatan_company_rate');

            $table->decimal('bpjs_jht_employee_rate', 5, 2)->default(2.00)->after('bpjs_tk_liability_account_id');
            $table->decimal('bpjs_jht_company_rate', 5, 2)->default(3.70)->after('bpjs_jht_employee_rate');
            $table->decimal('bpjs_jp_employee_rate', 5, 2)->default(1.00)->after('bpjs_jht_company_rate');
            $table->decimal('bpjs_jp_company_rate', 5, 2)->default(2.00)->after('bpjs_jp_employee_rate');
            $table->decimal('bpjs_jkk_company_rate', 5, 2)->default(0.24)->after('bpjs_jp_company_rate');
            $table->decimal('bpjs_jkm_company_rate', 5, 2)->default(0.30)->after('bpjs_jkk_company_rate');
            $table->decimal('bpjs_jp_max_base', 14, 2)->default(10547400)->after('bpjs_jkm_company_rate');

            $table->boolean('pph21_enabled')->default(false)->after('pph21_liability_account_id');

            $table->foreign('thr_expense_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('bpjs_company_expense_account_id')->references('id')->on('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sdm_payroll_setting', function (Blueprint $table) {
            $table->dropForeign(['thr_expense_account_id']);
            $table->dropForeign(['bpjs_company_expense_account_id']);
            $table->dropColumn([
                'thr_expense_account_id',
                'bpjs_company_expense_account_id',
                'bpjs_kesehatan_employee_rate',
                'bpjs_kesehatan_company_rate',
                'bpjs_kesehatan_max_base',
                'bpjs_jht_employee_rate',
                'bpjs_jht_company_rate',
                'bpjs_jp_employee_rate',
                'bpjs_jp_company_rate',
                'bpjs_jkk_company_rate',
                'bpjs_jkm_company_rate',
                'bpjs_jp_max_base',
                'pph21_enabled',
            ]);
        });
    }
};
