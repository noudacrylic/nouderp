<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Pengaturan "Noud Bot" Telegram (singleton id=1). Lihat TelegramNotifier
 * (outbound) & TelegramWebhookController (inbound linking chat_id).
 */
class TelegramSetting extends Model
{
    protected $fillable = [
        'bot_token', 'bot_username', 'webhook_secret', 'admin_chat_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }

    /** Versi ringan untuk runtime (notifier/webhook) — tanpa membuat baris baru. */
    public static function current(): ?self
    {
        return self::query()->first();
    }

    public function isConfigured(): bool
    {
        return $this->is_active && ! empty($this->bot_token);
    }

    /** Pastikan ada webhook_secret; buat bila belum ada. */
    public function ensureWebhookSecret(): string
    {
        if (empty($this->webhook_secret)) {
            $this->webhook_secret = Str::random(48);
            $this->save();
        }
        return $this->webhook_secret;
    }

    /** Username tanpa '@' (normalisasi input). */
    public function usernameClean(): ?string
    {
        return $this->bot_username ? ltrim($this->bot_username, '@') : null;
    }
}
