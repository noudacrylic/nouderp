<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionTargetRevision extends Model
{
    protected $fillable = [
        'production_order_id', 'from_planned_qty', 'to_planned_qty',
        'from_planned_cycles', 'to_planned_cycles',
        'outputs_before', 'outputs_after', 'reason', 'user_id',
    ];

    protected $casts = [
        'from_planned_qty'    => 'decimal:4',
        'to_planned_qty'      => 'decimal:4',
        'from_planned_cycles' => 'decimal:4',
        'to_planned_cycles'   => 'decimal:4',
        'outputs_before'      => 'array',
        'outputs_after'       => 'array',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
