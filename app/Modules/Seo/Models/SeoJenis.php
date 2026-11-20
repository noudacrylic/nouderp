<?php

namespace App\Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jenis produk bahan riset kata kunci (kategori toko + jenis custom). */
class SeoJenis extends Model
{
    protected $table = 'seo_jenis';

    protected $fillable = ['nama', 'petunjuk', 'aktif', 'urutan', 'riset_diminta_at', 'riset_at', 'riset_ringkasan', 'riset_biaya_rp'];

    protected $casts = ['aktif' => 'boolean', 'riset_diminta_at' => 'datetime', 'riset_at' => 'datetime'];

    public function kataKunci(): HasMany
    {
        return $this->hasMany(SeoKataKunci::class, 'seo_jenis_id');
    }
}
