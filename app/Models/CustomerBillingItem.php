<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerBillingItem extends Model
{
    protected $fillable = [
        'customer_billing_id',
        'invoice_id',
        'sales_order_id',
        'document_number',
        'document_date',
        'amount_snapshot',
        'remaining_snapshot',
    ];

    public function billing()
    {
        return $this->belongsTo(CustomerBilling::class, 'customer_billing_id');
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function salesOrder()
    {
        return $this->belongsTo(\App\Modules\Sales\Models\SalesOrder::class, 'sales_order_id');
    }
}
