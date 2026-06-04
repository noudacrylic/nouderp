<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionTier extends Model
{
    protected $fillable = [
        'promotion_id',
        'min_spend',
        'discount_type',
        'discount_value',
    ];

    protected $casts = [
        'min_spend'      => 'decimal:2',
        'discount_value' => 'decimal:2',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
