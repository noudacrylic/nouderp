<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Jasa kirim manual (ekspedisi non-API). Hanya daftar nama; ongkir diisi manual
 * saat transaksi. Muncul di kartu Pengiriman SO/Invoice bersama kurir API.
 */
class ManualCourier extends Model
{
    protected $fillable = ['name', 'code', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Hanya yang aktif, terurut (untuk ditampilkan di form). */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Peta code → name (di-memoize per request).
     *
     * Pakai `once()`, bukan `static $map`: variabel statis bertahan seumur PROSES PHP, jadi
     * kurir manual yang baru ditambah tak pernah terlihat oleh worker antrean yang sudah
     * berjalan (dan bocor antar-tes). `once()` dibersihkan tiap request/tes.
     */
    protected static function codeNameMap(): array
    {
        return once(fn () => static::pluck('name', 'code')->all());
    }

    /** Apakah kode kurir ini termasuk kurir manual? */
    public static function isManualCode(?string $code): bool
    {
        return $code ? array_key_exists($code, static::codeNameMap()) : false;
    }

    /** Nama tampil kurir manual untuk sebuah code (null bila bukan kurir manual). */
    public static function nameFor(?string $code): ?string
    {
        return $code ? (static::codeNameMap()[$code] ?? null) : null;
    }

    /** Bikin code unik dari nama (slug). */
    public static function makeCode(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'kurir';
        $code = $base;
        $i = 2;
        while (static::where('code', $code)->exists()) {
            $code = $base . '_' . $i++;
        }
        return $code;
    }
}
