<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesInvoiceItem extends Model
{
    protected $guarded = [];

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }

    // Accessors for UI Consistency
    public function getDiscountPerUnitAttribute()
    {
        if ($this->discount_type === 'percent') {
            return $this->unit_price * ($this->discount_value / 100);
        }
        return (float) $this->discount_value;
    }

    public function getLineSubtotalAttribute()
    {
        return $this->qty * $this->unit_price;
    }

    public function getLineDiscountAttribute()
    {
        return $this->discount_amount; // summary of (qty * discount_per_unit) already in DB
    }

    public function getLineTotalAttribute()
    {
        return $this->subtotal; // net total already in DB
    }
}
