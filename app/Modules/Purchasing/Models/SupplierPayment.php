<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Supplier;
use App\Core\Accounting\Account;

class SupplierPayment extends Model
{
    protected $fillable = [
        'payment_number',
        'payment_date',
        'supplier_id',
        'payment_method',
        'bank_account_id',
        'amount',
        'allocated_amount',
        'remaining_amount',
        'overpay',
        'used_balance',
        'status',
        'posted_at',
        'voided_at',
        'journal_id',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function allocations()
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function canBeVoided(): bool
    {
        return strtolower((string) $this->status) === 'posted';
    }
}
