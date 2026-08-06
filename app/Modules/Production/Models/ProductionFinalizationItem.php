<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionFinalizationItem extends Model
{
    protected $fillable = [
        'production_finalization_id', 'production_order_output_id', 'product_id',
        'qty', 'cost', 'unit_cost', 'percentage', 'warehouse_allocations', 'variance_notes',
    ];

    protected $casts = [
        'qty'                   => 'decimal:4',
        'cost'                  => 'decimal:4',
        'unit_cost'             => 'decimal:4',
        'percentage'            => 'decimal:4',
        'warehouse_allocations' => 'array',
    ];

    public function finalization()
    {
        return $this->belongsTo(ProductionFinalization::class, 'production_finalization_id');
    }

    public function output()
    {
        return $this->belongsTo(ProductionOrderOutput::class, 'production_order_output_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
