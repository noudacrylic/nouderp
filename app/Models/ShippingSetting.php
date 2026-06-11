<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Setting per-provider kurir (pola singleton-per-provider, mirip MidtransSetting).
 * Ambil via ShippingSetting::for('biteship').
 */
class ShippingSetting extends Model
{
    protected $fillable = [
        'provider', 'is_enabled', 'is_production', 'api_key', 'base_url', 'webhook_token', 'config',
    ];

    protected $casts = [
        'is_enabled'    => 'boolean',
        'is_production' => 'boolean',
        'config'        => 'array',
    ];

    /** Default base URL per provider bila tidak di-set manual. */
    public const DEFAULT_BASE_URL = [
        'biteship'   => 'https://api.biteship.com',
        // KiriminAja: sandbox=tdev, produksi=client. Biarkan null → KiriminAjaProvider
        // otomatis pilih tdev (sandbox) / client (produksi) sesuai is_production.
        'kiriminaja' => null,
    ];

    /** Ambil (atau buat) baris setting untuk provider tertentu. */
    public static function for(string $provider): self
    {
        return static::firstOrCreate(
            ['provider' => $provider],
            ['base_url' => self::DEFAULT_BASE_URL[$provider] ?? null]
        );
    }

    /** Base URL efektif (fallback ke default provider). */
    public function effectiveBaseUrl(): string
    {
        return rtrim($this->base_url ?: (self::DEFAULT_BASE_URL[$this->provider] ?? ''), '/');
    }

    public function isConfigured(): bool
    {
        return $this->is_enabled && !empty($this->api_key);
    }
}
