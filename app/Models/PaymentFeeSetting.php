<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;

class PaymentFeeSetting extends Model
{
    protected $fillable = [
        'cash_account_id',
        'fee_flat',
        'fee_percent',
        'expense_account_id',
    ];

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function expenseAccount()
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}
