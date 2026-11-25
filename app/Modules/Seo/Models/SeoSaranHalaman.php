<?php

namespace App\Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris laporan "tampil tapi tak diklik" — lihat migrasi 2026_11_25_140000. */
class SeoSaranHalaman extends Model
{
    protected $table = 'seo_saran_halaman';

    protected $fillable = ['url', 'jenis', 'store_product_id', 'tayang', 'klik', 'posisi', 'kueri', 'judul_sekarang',
        'deskripsi_sekarang', 'judul_usulan', 'deskripsi_usulan', 'alasan', 'status', 'dipakai_at'];

    protected $casts = ['kueri' => 'array', 'posisi' => 'float', 'dipakai_at' => 'datetime'];

    public function ctr(): float
    {
        return $this->tayang ? $this->klik / $this->tayang * 100 : 0;
    }
}
