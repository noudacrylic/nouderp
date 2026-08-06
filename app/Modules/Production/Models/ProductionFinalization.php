<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu kali pelepasan hasil produksi ke stok (batch).
 *
 * Order biasa punya 1 batch (is_closing = true). Order yang diambil sebagian punya
 * beberapa batch partial + 1 batch penutup yang menyapu sisa WIP.
 */
class ProductionFinalization extends Model
{
    protected $fillable = [
        'production_order_id', 'sequence', 'is_closing', 'wip_released', 'wip_total_snapshot',
        'journal_id', 'void_journal_id', 'voided_at', 'created_by', 'notes',
    ];

    protected $casts = [
        'is_closing'         => 'boolean',
        'wip_released'       => 'decimal:4',
        'wip_total_snapshot' => 'decimal:4',
        'voided_at'          => 'datetime',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function items()
    {
        return $this->hasMany(ProductionFinalizationItem::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('voided_at');
    }

    public function label(): string
    {
        return $this->is_closing ? 'Penutup' : "Partial #{$this->sequence}";
    }
}
