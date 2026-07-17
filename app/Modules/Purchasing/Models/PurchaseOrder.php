<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Supplier;
use App\Core\Inventory\Warehouse;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number',
        'supplier_id',
        'warehouse_id',
        'po_date',
        'expected_date',
        'supplier_invoice_no',
        'subtotal',
        'discount_total',
        'global_discount_type',
        'global_discount_value',
        'global_discount_amount',
        'expense_capitalized_total',
        'expense_direct_total',
        'ppn_percent',
        'ppn_amount',
        'total',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'po_date' => 'date',
        'expected_date' => 'date',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function expenses()
    {
        return $this->hasMany(PurchaseOrderExpense::class);
    }

    public function invoices()
    {
        return $this->hasMany(PurchaseInvoice::class);
    }
}
