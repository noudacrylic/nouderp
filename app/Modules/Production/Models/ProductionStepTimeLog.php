<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionStepTimeLog extends Model
{
    protected $fillable = [
        'production_order_step_id', 'event_type', 'occurred_at', 'notes',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function step()
    {
        return $this->belongsTo(ProductionOrderStep::class, 'production_order_step_id');
    }
}
