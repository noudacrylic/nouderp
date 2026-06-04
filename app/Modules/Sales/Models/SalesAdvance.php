<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class SalesAdvance extends Model
{
    protected $table = 'sales_advances';

    protected $fillable = [
        'advance_number',
        'sales_order_id',
        'customer_id',
        'bank_account_id',
        'advance_date',
        'amount',
        'use_credit',
        'credit_amount',
        'credit_used',
        'applied_amount',
        'status',
        'notes'
    ];

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function account()
    {
        return $this->belongsTo(\App\Core\Accounting\Account::class, 'bank_account_id');
    }
}
