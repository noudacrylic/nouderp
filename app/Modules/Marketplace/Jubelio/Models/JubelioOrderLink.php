<?php

namespace App\Modules\Marketplace\Jubelio\Models;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Link 1 pesanan Jubelio ke 1 Sales Order ERP, dengan flag idempotensi per tahap.
 */
class JubelioOrderLink extends Model
{
    protected $fillable = [
        'jubelio_salesorder_id',
        'jubelio_salesorder_no',
        'sales_order_id',
        'store',
        'last_status',
        'dp_posted',
        'sj_created',
        'invoice_posted',
        'return_created',
        'jubelio_invoice_id',
        'customer_payment_id',
        'last_error',
    ];

    protected $casts = [
        'dp_posted'      => 'boolean',
        'sj_created'     => 'boolean',
        'invoice_posted' => 'boolean',
        'return_created' => 'boolean',
    ];

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
