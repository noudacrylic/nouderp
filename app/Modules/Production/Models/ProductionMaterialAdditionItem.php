<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionMaterialAdditionItem extends Model
{
    protected $fillable = ['addition_id', 'product_id', 'qty_requested', 'unit', 'notes'];

    protected $casts = ['qty_requested' => 'decimal:4'];

    public function addition()
    {
        return $this->belongsTo(ProductionMaterialAddition::class, 'addition_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
