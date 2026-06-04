<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    protected $fillable = [
        'order_number', 'type', 'bom_id', 'sales_order_id', 'warehouse_id',
        'planned_cycles', 'planned_qty', 'production_date',
        'repair_source_type', 'repair_source_ref', 'repair_source_id',
        'score_type', 'priority_level',
        'status', 'created_via', 'finalized_at', 'notes', 'description', 'image_paths',
    ];

    protected $casts = [
        'production_date' => 'date',
        'finalized_at'    => 'datetime',
        'planned_cycles'  => 'decimal:4',
        'planned_qty'     => 'decimal:4',
        'image_paths'     => 'array',
    ];

    public function bom()
    {
        return $this->belongsTo(Bom::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(\App\Modules\Sales\Models\SalesOrder::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(\App\Core\Inventory\Warehouse::class);
    }

    public function materials()
    {
        return $this->hasMany(ProductionOrderMaterial::class);
    }

    public function outputs()
    {
        return $this->hasMany(ProductionOrderOutput::class);
    }

    public function steps()
    {
        return $this->hasMany(ProductionOrderStep::class)->orderBy('step_number');
    }

    public function sources()
    {
        return $this->hasMany(ProductionOrderSource::class);
    }

    public function costs()
    {
        return $this->hasMany(ProductionOrderCost::class);
    }

    public function getEffectiveScoreAttribute(): float
    {
        if ($this->score_type === 'priority') {
            return match($this->priority_level) {
                'very_high' => 300.0,
                'high'      => 200.0,
                'medium'    => 100.0,
                default     => 0.0,
            };
        }

        $bomScore = (float) ($this->bom?->score ?? 0);

        // Order custom/preorder selalu prioritas teratas (floor 300), selaras dengan
        // bobot preorder 300 di BomScoreService. Berlaku walau order tidak punya BOM.
        if ($this->type === 'custom') {
            return max(300.0, $bomScore);
        }

        return $bomScore;
    }

    public function getPriorityLabelAttribute(): string
    {
        return match($this->priority_level) {
            'very_high' => 'Sangat Tinggi',
            'high'      => 'Tinggi',
            'medium'    => 'Sedang',
            'low'       => 'Rendah',
            default     => '—',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'ready_stock' => 'Ready Stock',
            'custom'      => 'Custom / Preorder',
            'repair'      => 'Perbaikan',
            default       => ucfirst($this->type),
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'draft'       => 'Draft',
            'confirmed'   => 'Dikonfirmasi',
            'in_progress' => 'Dalam Proses',
            'completed'   => 'Menunggu Finalisasi',
            'pending'     => 'Menunggu Stok',
            'finalized'   => 'Selesai',
            'cancelled'   => 'Dibatalkan',
            default       => ucfirst($this->status),
        };
    }

    public function getCurrentStepAttribute(): ?ProductionOrderStep
    {
        return $this->steps->where('status', 'in_progress')->first()
            ?? $this->steps->where('status', 'pending')->first();
    }
}
