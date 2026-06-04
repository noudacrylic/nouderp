<?php

namespace App\Modules\FixedAsset\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Inventory\Warehouse;

class FixedAssetTransfer extends Model
{
    protected $fillable = [
        'transfer_number',
        'fixed_asset_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'transfer_date',
        'notes',
        'status',
        'posted_at',
        'posted_by',
        'voided_at',
        'created_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function canBeVoided(): bool
    {
        if ($this->status !== 'posted') return false;

        // Hanya transfer terbaru per asset yang boleh di-void.
        // Why: void transfer lama akan membuat warehouse_id melompat-lompat tidak konsisten
        // dengan riwayat — user harus void yang lebih baru dulu (LIFO).
        $newer = static::where('fixed_asset_id', $this->fixed_asset_id)
            ->where('status', 'posted')
            ->where('id', '>', $this->id)
            ->exists();
        return !$newer;
    }
}
