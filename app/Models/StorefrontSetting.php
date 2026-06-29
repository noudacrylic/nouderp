<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Pengaturan jembatan API etalase — singleton (id=1). Dikelola di Settings →
 * Integrasi → Etalase Website. api_key dipakai VPS/etalase (server-side) untuk
 * mengakses endpoint baca /api/storefront/* (lihat StorefrontApiKey middleware).
 */
class StorefrontSetting extends Model
{
    protected $fillable = ['is_active', 'api_key'];

    protected $casts = ['is_active' => 'boolean'];

    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }

    public static function current(): ?self
    {
        return self::query()->first();
    }

    public function isConfigured(): bool
    {
        return $this->is_active && !empty($this->api_key);
    }

    public static function generateKey(): string
    {
        return 'sk_store_' . Str::random(48);
    }
}
