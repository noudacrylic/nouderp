<?php

namespace App\Modules\Galeri\Models;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Info foto satu pesanan — lihat migrasi 2026_11_16_170000. */
class GaleriKarya extends Model
{
    protected $table = 'galeri_karya';

    protected $fillable = ['sales_order_id', 'lini', 'folder', 'layak_galeri', 'nama_instansi', 'jenis_produk',
        'foto_utama', 'catatan', 'tanpa_foto_alasan', 'user_id'];

    protected $casts = ['layak_galeri' => 'boolean'];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
