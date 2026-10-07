<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kelompok Perbaikan — kumpulan barang Gudang Perbaikan yang akan diperbaiki dalam SATU OP.
 * Tidak menyentuh stok maupun jurnal; hanya memesan qty (lihat RepairQueueService).
 */
class RepairGroup extends Model
{
    public const TERBUKA = 'terbuka';
    public const DI_OP   = 'di_op';
    public const SELESAI = 'selesai';
    public const BATAL   = 'batal';

    public const STATUS_LABEL = [
        self::TERBUKA => 'Terbuka',
        self::DI_OP   => 'Di OP',
        self::SELESAI => 'Selesai',
        self::BATAL   => 'Batal',
    ];

    protected $fillable = ['number', 'name', 'status', 'production_order_id', 'notes', 'created_by'];

    public function items()
    {
        return $this->hasMany(RepairGroupItem::class);
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function isTerbuka(): bool
    {
        return $this->status === self::TERBUKA;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABEL[$this->status] ?? $this->status;
    }
}
