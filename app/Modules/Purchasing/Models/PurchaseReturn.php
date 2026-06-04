<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Supplier;
use App\Core\Inventory\Warehouse;

class PurchaseReturn extends Model
{
    protected $fillable = [
        'return_number',
        'return_date',
        'return_type',
        'supplier_id',
        'purchase_invoice_id',
        'purchase_order_id',
        'warehouse_id',
        'subtotal',
        'ppn_amount',
        'total',
        'refund_amount',
        'dp_allocation',
        'status',
        'posted_at',
        'voided_at',
        'journal_id',
        'notes',
    ];

    protected $casts = [
        'return_date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'dp_allocation' => 'array',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function canBeVoided(): bool
    {
        return strtolower((string) $this->status) === 'posted';
    }
}
