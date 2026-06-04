<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAdjustmentItem extends Model
{
    protected $fillable = [
        'adjustment_id',
        'product_id',
        'system_qty',
        'actual_qty',
        'diff_qty',
        'notes'
    ];

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
