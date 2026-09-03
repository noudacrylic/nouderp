<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Setting per-provider chat (pola singleton-per-provider, sama seperti ShippingSetting).
 * Ambil via CrmSetting::for('apicoid').
 */
class CrmSetting extends Model
{
    protected $fillable = [
        'provider', 'is_enabled', 'api_key', 'base_url',
        'webhook_secret', 'default_phone_number_id', 'config',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config'     => 'array',
    ];

    /** Kunci HMAC & API key jangan pernah ikut terbawa ke JSON/log. */
    protected $hidden = ['api_key', 'webhook_secret'];

    public const DEFAULT_BASE_URL = [
        'apicoid' => 'https://chat.api.co.id/api/v1/public',
    ];

    public static function for(string $provider): self
    {
        return static::firstOrCreate(
            ['provider' => $provider],
            ['base_url' => self::DEFAULT_BASE_URL[$provider] ?? null]
        );
    }

    public function effectiveBaseUrl(): string
    {
        return rtrim($this->base_url ?: (self::DEFAULT_BASE_URL[$this->provider] ?? ''), '/');
    }

    public function isConfigured(): bool
    {
        return $this->is_enabled && ! empty($this->api_key);
    }
}
