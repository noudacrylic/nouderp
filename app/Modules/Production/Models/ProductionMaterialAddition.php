<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionMaterialAddition extends Model
{
    protected $fillable = [
        'production_order_id', 'production_order_step_id', 'addition_number', 'notes',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function step()
    {
        return $this->belongsTo(ProductionOrderStep::class, 'production_order_step_id');
    }

    public function items()
    {
        return $this->hasMany(ProductionMaterialAdditionItem::class, 'addition_id');
    }

    public function costs()
    {
        return $this->hasMany(ProductionOrderCost::class, 'material_addition_id');
    }
}
