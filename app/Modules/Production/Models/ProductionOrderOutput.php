<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderOutput extends Model
{
    protected $fillable = [
        'production_order_id', 'product_id', 'qty_planned', 'qty_produced', 'output_type', 'percentage', 'unit_percentage', 'variance_notes',
    ];

    protected $casts = [
        'qty_planned'     => 'decimal:4',
        'qty_produced'    => 'decimal:4',
        'percentage'      => 'decimal:2',
        'unit_percentage' => 'decimal:4',
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
