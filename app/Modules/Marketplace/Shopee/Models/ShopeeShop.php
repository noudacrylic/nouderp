<?php

namespace App\Modules\Marketplace\Shopee\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Toko Shopee yang sudah diotorisasi lewat Shopee Open Platform. Token terenkripsi
 * (APP_KEY) — jangan pernah ditampilkan atau ditulis ke log.
 */
class ShopeeShop extends Model
{
    protected $fillable = [
        'shop_id', 'env', 'nama', 'access_token', 'refresh_token', 'access_expires_at',
        'refresh_expires_at', 'terhubung_oleh', 'terhubung_at', 'galat_terakhir',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'shop_id'            => 'integer',
        'access_token'       => 'encrypted',
        'refresh_token'      => 'encrypted',
        'access_expires_at'  => 'datetime',
        'refresh_expires_at' => 'datetime',
        'terhubung_at'       => 'datetime',
    ];

    public function penghubung(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terhubung_oleh');
    }

    /** Toko aktif untuk lingkungan (test/live) yang sedang dipakai. */
    public static function aktif(): ?self
    {
        return static::where('env', ShopeeSetting::singleton()->envAktif())->latest('terhubung_at')->first();
    }

    /** Refresh token kedaluwarsa → toko harus dihubungkan ulang dari halaman Integrasi. */
    public function perluHubungkanUlang(): bool
    {
        return !$this->refresh_token || ($this->refresh_expires_at && $this->refresh_expires_at->isPast());
    }
}
