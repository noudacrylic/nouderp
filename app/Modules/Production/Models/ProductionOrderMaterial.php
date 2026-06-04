<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderMaterial extends Model
{
    protected $fillable = [
        'production_order_id', 'product_id', 'qty_required', 'qty_consumed', 'unit', 'notes',
    ];

    protected $casts = [
        'qty_required' => 'decimal:4',
        'qty_consumed'  => 'decimal:4',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
