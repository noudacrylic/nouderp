<?php

namespace App\Modules\Production\PrintRusak;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/** Satu laporan lembar bahan print yang rusak. Lihat PrintRusakService. */
class PrintRusak extends Model
{
    protected $table = 'print_rusak';

    protected $guarded = ['id'];

    protected $casts = [
        'tanggal'   => 'date',
        'qty'       => 'float',
        'nilai'     => 'float',
        'voided_at' => 'datetime',
    ];

    /** Sebab kerusakan — paperjam paling sering (7 Nov 2026). */
    public const SEBAB = [
        'paperjam' => 'Paperjam',
        'tinta'    => 'Tinta / warna',
        'ukuran'   => 'Salah ukuran / posisi',
        'desain'   => 'Salah desain',
        'lain'     => 'Lainnya',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function productionOrder() { return $this->belongsTo(ProductionOrder::class); }
    public function salesOrder() { return $this->belongsTo(SalesOrder::class); }
    public function user() { return $this->belongsTo(User::class); }

    public function isVoided(): bool { return $this->voided_at !== null; }

    /** Pelapor boleh membatalkan laporannya di hari yang sama; super admin kapan pun. */
    public function canBeVoided(?User $u): bool
    {
        if ($this->isVoided() || !$u) return false;
        if ($u->role === 'super_admin') return true;
        return $this->user_id === $u->id && $this->created_at?->isToday();
    }

    public function labelSebab(): string { return self::SEBAB[$this->sebab] ?? $this->sebab; }
}
