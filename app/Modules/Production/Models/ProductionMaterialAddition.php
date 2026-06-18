<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionMaterialAddition extends Model
{
    protected $fillable = [
        'production_order_id', 'production_order_step_id', 'addition_number', 'notes', 'voided_at',
    ];

    protected $casts = [
        'voided_at' => 'datetime',
    ];

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Boleh di-void hanya bila belum dibatalkan DAN pengerjaan produksinya belum selesai
     * (order masih 'in_progress'). Setelah completed/finalized, biaya bahan sudah masuk
     * barang jadi sehingga tidak bisa dibalik dari sini.
     */
    public function canBeVoided(): bool
    {
        return !$this->isVoided()
            && optional($this->productionOrder)->status === 'in_progress';
    }

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
