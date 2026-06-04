<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCostLayer extends Model
{
    protected $fillable = [
        'product_id',
        'qty_in',
        'qty_out',
        'qty_balance',
        'unit_cost',
        'reference_type',
        'reference_id'
    ];

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
