<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan Claude AI (Anthropic) — singleton (id=1). Lihat ClaudeClient
 * & AiAccountantService. Nilai DB diutamakan; config('services.anthropic.*')
 * (dari .env) jadi fallback.
 */
class AnthropicSetting extends Model
{
    protected $fillable = [
        'api_key', 'model_text', 'model_vision', 'confirm_threshold', 'is_active',
    ];

    protected $casts = [
        'confirm_threshold' => 'integer',
        'is_active'         => 'boolean',
    ];

    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }

    /** Versi ringan untuk runtime — tanpa membuat baris baru. */
    public static function current(): ?self
    {
        return self::query()->first();
    }

    public function isConfigured(): bool
    {
        return $this->is_active && ! empty($this->api_key);
    }
}
