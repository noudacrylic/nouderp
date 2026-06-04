<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesQuotationItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class, 'product_id');
    }

    public function getLineSubtotalAttribute()
    {
        return $this->qty * $this->unit_price;
    }

    public function getLineDiscountAttribute()
    {
        $sub = $this->line_subtotal;
        if ($this->discount_type == 'percent') {
            return $sub * ($this->discount_value / 100);
        }
        return $this->discount_value;
    }

    public function getLineTotalAttribute()
    {
        return $this->line_subtotal - $this->line_discount;
    }

    public function getDiscountPerUnitAttribute()
    {
        if ($this->qty > 0) {
            return $this->line_discount / $this->qty;
        }
        return 0;
    }
}
