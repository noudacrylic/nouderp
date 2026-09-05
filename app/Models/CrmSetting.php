<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

    /**
     * Token rahasia di PATH webhook — jalur verifikasi kedua.
     *
     * Dibuat karena api.co.id menandatangani webhook (X-Webhook-Signature) tapi
     * tidak memperlihatkan signing secret-nya di mana pun, sehingga HMAC tak
     * bisa diverifikasi. Tanpa jalur ini pilihannya cuma dua dan keduanya buruk:
     * menolak semua webhook, atau menerima payload tanpa verifikasi sama sekali.
     *
     * Token acak 40 karakter di dalam URL HTTPS = pola yang sudah dipakai ERP
     * ini untuk Telegram (`/telegram/webhook/{secret}`) dan QRISLY. Begitu
     * signing secret vendor ketemu, HMAC otomatis kembali jadi penjaga utama.
     */
    public function webhookToken(): string
    {
        $token = (string) ($this->config['webhook_url_token'] ?? '');

        if ($token === '') {
            $token = Str::random(40);
            $this->config = array_merge((array) $this->config, ['webhook_url_token' => $token]);
            $this->save();
        }

        return $token;
    }

    /** Bikin token baru (URL lama langsung mati). */
    public function regenerateWebhookToken(): string
    {
        $this->config = array_merge((array) $this->config, ['webhook_url_token' => Str::random(40)]);
        $this->save();

        return $this->config['webhook_url_token'];
    }

    public function isConfigured(): bool
    {
        return $this->is_enabled && ! empty($this->api_key);
    }
}
