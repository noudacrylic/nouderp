<?php

namespace App\Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu kata kunci di bank SEO — lihat migrasi 2026_11_20_100000. */
class SeoKataKunci extends Model
{
    protected $table = 'seo_kata_kunci';

    protected $fillable = ['seo_jenis_id', 'kata', 'niat', 'perkiraan', 'sumber', 'aktif', 'catatan',
        'gsc_tayang', 'gsc_klik', 'gsc_posisi', 'gsc_sampai'];

    protected $casts = ['aktif' => 'boolean', 'gsc_sampai' => 'date', 'gsc_posisi' => 'float'];

    public const NIAT = ['beli' => 'Beli', 'custom' => 'Pesan custom', 'lokal' => 'Lokal', 'info' => 'Info'];

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(SeoJenis::class, 'seo_jenis_id');
    }

    /** Bentuk baku: huruf kecil, spasi tunggal. */
    public static function baku(string $kata): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($kata)));
    }
}
