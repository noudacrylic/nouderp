<?php

namespace App\Modules\SDM\Models;

use App\Core\Accounting\Account;
use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    protected $table = 'sdm_payroll_setting';

    protected $fillable = [
        'expense_salary_account_id',
        'thr_expense_account_id',
        'bpjs_company_expense_account_id',
        'bpjs_kesehatan_liability_account_id',
        'bpjs_kesehatan_employee_rate',
        'bpjs_kesehatan_company_rate',
        'bpjs_kesehatan_max_base',
        'bpjs_tk_liability_account_id',
        'bpjs_jht_employee_rate',
        'bpjs_jht_company_rate',
        'bpjs_jp_employee_rate',
        'bpjs_jp_company_rate',
        'bpjs_jkk_company_rate',
        'bpjs_jkm_company_rate',
        'bpjs_jp_max_base',
        'pph21_liability_account_id',
        'pph21_enabled',
        'kasbon_receivable_account_id',
    ];

    protected $casts = [
        'bpjs_kesehatan_employee_rate' => 'decimal:2',
        'bpjs_kesehatan_company_rate'  => 'decimal:2',
        'bpjs_kesehatan_max_base'      => 'decimal:2',
        'bpjs_jht_employee_rate'       => 'decimal:2',
        'bpjs_jht_company_rate'        => 'decimal:2',
        'bpjs_jp_employee_rate'        => 'decimal:2',
        'bpjs_jp_company_rate'         => 'decimal:2',
        'bpjs_jkk_company_rate'        => 'decimal:2',
        'bpjs_jkm_company_rate'        => 'decimal:2',
        'bpjs_jp_max_base'             => 'decimal:2',
        'pph21_enabled'                => 'boolean',
    ];

    public static function singleton(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    public function expenseSalaryAccount()      { return $this->belongsTo(Account::class, 'expense_salary_account_id'); }
    public function thrExpenseAccount()         { return $this->belongsTo(Account::class, 'thr_expense_account_id'); }
    public function bpjsCompanyExpenseAccount() { return $this->belongsTo(Account::class, 'bpjs_company_expense_account_id'); }
    public function bpjsKesehatanAccount()      { return $this->belongsTo(Account::class, 'bpjs_kesehatan_liability_account_id'); }
    public function bpjsTkAccount()             { return $this->belongsTo(Account::class, 'bpjs_tk_liability_account_id'); }
    public function pph21Account()              { return $this->belongsTo(Account::class, 'pph21_liability_account_id'); }
    public function kasbonReceivableAccount()   { return $this->belongsTo(Account::class, 'kasbon_receivable_account_id'); }
}
