<?php

namespace App\Modules\Marketplace\Jubelio\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Setting singleton integrasi Jubelio (1 baris, id=1) — pola seperti MidtransSetting.
 * Ambil via JubelioSetting::singleton().
 */
class JubelioSetting extends Model
{
    public const DEFAULT_BASE_URL = 'https://api2.jubelio.com';

    protected $fillable = [
        'username',
        'password',
        'access_token',
        'token_expires_at',
        'base_url',
        'is_production',
        'is_active',
        'webhook_secret',
        'default_warehouse_id',
        'default_location_id',
        'default_customer_id',
        'config',
    ];

    protected $casts = [
        'password'          => 'encrypted',
        'token_expires_at'  => 'datetime',
        'is_production'     => 'boolean',
        'is_active'         => 'boolean',
        'config'            => 'array',
    ];

    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1], ['base_url' => self::DEFAULT_BASE_URL]);
    }

    /** Base URL efektif (fallback ke default). */
    public function effectiveBaseUrl(): string
    {
        return rtrim($this->base_url ?: self::DEFAULT_BASE_URL, '/');
    }

    public function isConfigured(): bool
    {
        return $this->is_active && !empty($this->username) && !empty($this->password);
    }

    /** Token tersimpan masih berlaku? (beri buffer 5 menit sebelum expiry 12 jam). */
    public function hasValidToken(): bool
    {
        return !empty($this->access_token)
            && $this->token_expires_at
            && $this->token_expires_at->isAfter(now()->addMinutes(5));
    }
}
