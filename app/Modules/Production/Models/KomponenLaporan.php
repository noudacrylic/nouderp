<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Laporan komponen kurang/rusak dari Pending Assembling — lihat KomponenLaporanService. */
class KomponenLaporan extends Model
{
    protected $table = 'production_komponen_laporan';

    public const PENYEBAB = [
        'belum_dipotong' => ['label' => '✂ Belum dipotong / jumlah kurang dari CNC', 'pendek' => 'Kurang dari CNC', 'susulan' => true],
        'salah_potong'   => ['label' => '📐 Salah potong (ukuran, lubang, gosong)', 'pendek' => 'Salah potong', 'susulan' => true],
        'rusak_rakit'    => ['label' => '🔨 Rusak saat dirakit', 'pendek' => 'Rusak saat dirakit', 'susulan' => true],
        'bahan_beli'     => ['label' => '📦 Bahan beli belum ada (magnet, plastik, stiker…)', 'pendek' => 'Bahan beli belum ada', 'susulan' => false],
    ];

    protected $guarded = [];

    protected $casts = ['susulan_selesai_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(KomponenLaporanItem::class, 'laporan_id');
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function step()
    {
        return $this->belongsTo(ProductionOrderStep::class, 'production_order_step_id');
    }

    public function pelapor()
    {
        return $this->belongsTo(User::class, 'dilaporkan_by');
    }

    public function labelPenyebab(): string
    {
        return self::PENYEBAB[$this->penyebab]['pendek'] ?? $this->penyebab;
    }

    /** "sekat ×2, tutup ×1" */
    public function ringkasItem(): string
    {
        return $this->items->map(fn ($i) => "{$i->nama} ×{$i->jumlah}")->implode(', ');
    }
}
