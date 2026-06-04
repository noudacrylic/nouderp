<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Customer;
use App\Models\SalesInvoice;

class SalesReturn extends Model
{
    protected $fillable = [
        'return_number',
        'customer_id',
        'invoice_id',
        'sales_order_id',
        'return_date',
        'grand_total',
        'status',
        'notes'
    ];

    protected $casts = [
        'return_date' => 'date',
        'grand_total' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function items()
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function canBeVoided(): bool
    {
        if ($this->status !== 'posted') return false;

        $overpay = \App\Models\CustomerOverpayment::where('reference', $this->return_number)->first();
        if ($overpay) {
            $original = (float) $this->grand_total;
            $remaining = (float) $overpay->amount;
            if ($remaining + 0.0001 < $original) return false;
        }

        return true;
    }
}
