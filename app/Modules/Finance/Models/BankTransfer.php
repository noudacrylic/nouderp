<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;

class BankTransfer extends Model
{
    protected $fillable = [
        'number',
        'date',
        'from_account_id',
        'to_account_id',
        'admin_fee_account_id',
        'amount',
        'admin_fee',
        'fee_borne_by',
        'reference',
        'status',
        'journal_id',
        'posted_at',
        'voided_at',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'amount' => 'decimal:2',
        'admin_fee' => 'decimal:2',
    ];

    public function fromAccount()
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function adminFeeAccount()
    {
        return $this->belongsTo(Account::class, 'admin_fee_account_id');
    }

    public function canBeVoided(): bool
    {
        return $this->status === 'posted';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
