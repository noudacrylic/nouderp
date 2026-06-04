<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Supplier;
use App\Core\Inventory\Warehouse;

class PurchaseInvoice extends Model
{
    protected $fillable = [
        'invoice_number',
        'supplier_invoice_no',
        'supplier_invoice_date',
        'supplier_id',
        'warehouse_id',
        'purchase_order_id',
        'invoice_date',
        'due_date',
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
        'paid_amount',
        'outstanding_amount',
        'status',
        'posted_at',
        'voided_at',
        'posted_by',
        'journal_id',
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'supplier_invoice_date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function expenses()
    {
        return $this->hasMany(PurchaseInvoiceExpense::class);
    }

    public function returns()
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function paymentAllocations()
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function canBeVoided(): bool
    {
        if ($this->status !== 'posted') return false;

        if (SupplierPaymentAllocation::where('purchase_invoice_id', $this->id)
            ->whereHas('payment', fn($q) => $q->where('status', 'posted'))->exists()) return false;

        if (PurchaseReturn::where('purchase_invoice_id', $this->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])->exists()) return false;

        return true;
    }
}
