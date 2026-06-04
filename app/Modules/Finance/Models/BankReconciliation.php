<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;

class BankReconciliation extends Model
{
    protected $fillable = [
        'number',
        'account_id',
        'start_date',
        'end_date',
        'period_year',
        'period_month',
        'opening_balance',
        'book_balance',
        'statement_balance',
        'difference',
        'status',
        'completed_at',
        'voided_at',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'completed_at' => 'datetime',
        'voided_at' => 'datetime',
        'opening_balance' => 'decimal:2',
        'book_balance' => 'decimal:2',
        'statement_balance' => 'decimal:2',
        'difference' => 'decimal:2',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function lines()
    {
        return $this->hasMany(BankReconciliationLine::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function canBeVoided(): bool
    {
        return $this->status === 'completed';
    }
}
