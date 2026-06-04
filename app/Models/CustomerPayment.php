<?php

namespace App\Models;

use App\Core\Accounting\Account;
use Illuminate\Database\Eloquent\Model;

class CustomerPayment extends Model
{
    protected $fillable = [
        'payment_number',
        'customer_id',
        'date',
        'cash_account_id',
        'amount',
        'admin_fee',
        'fee_account_id',
        'payment_type',
        'sales_order_id',
        'used_balance',
        'overpay',
        'status',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function allocations()
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    public function invoices()
    {
        return $this->belongsToMany(\App\Models\SalesInvoice::class, 'customer_payment_allocations', 'customer_payment_id', 'invoice_id')
                    ->withPivot('amount');
    }

    public function salesOrders()
    {
        return $this->hasManyThrough(
            \App\Modules\Sales\Models\SalesOrder::class,
            CustomerPaymentAllocation::class,
            'customer_payment_id',
            'id',
            'id',
            'sales_order_id'
        );
    }

    public function salesOrder()
    {
        return $this->belongsTo(\App\Modules\Sales\Models\SalesOrder::class, 'sales_order_id');
    }

    public function feeAccount()
    {
        return $this->belongsTo(\App\Core\Accounting\Account::class, 'fee_account_id');
    }

    public function canBeVoided(): bool
    {
        return strtolower((string) $this->status) === 'posted';
    }
}
