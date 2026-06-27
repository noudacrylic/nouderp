<?php

namespace App\Modules\Assistant\Services;

use App\Models\AnthropicSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pemanggil ringan Claude Messages API (api.anthropic.com) tanpa SDK — pakai Http
 * facade, pola defensif spt JubelioClient/MidtransService (tidak melempar; balas array).
 */
class ClaudeClient
{
    private string $key;
    private string $base = 'https://api.anthropic.com/v1';

    public function __construct()
    {
        // Sumber utama: pengaturan DB (Settings → Integrasi → Claude AI).
        // Fallback ke config/.env supaya tetap jalan tanpa setting UI.
        $setting = AnthropicSetting::current();

        $this->key = ($setting && $setting->is_active)
            ? (string) ($setting->api_key ?: config('services.anthropic.key', ''))
            : (string) config('services.anthropic.key', '');
    }

    public function enabled(): bool
    {
        return $this->key !== '';
    }

    // Status transient yang aman dicoba-ulang (overload/rate-limit/server error).
    private const RETRYABLE = [429, 500, 502, 503, 529];

    /**
     * Kirim request ke /messages dengan auto-retry pada error transient
     * (mis. 529 overloaded). Return respons terdekode, atau
     * ['_error' => '...', '_status' => int|null].
     */
    public function messages(array $payload): array
    {
        if (! $this->enabled()) {
            return ['_error' => 'ANTHROPIC_API_KEY belum diatur (Settings → Integrasi → Claude AI atau .env).'];
        }

        $maxAttempts = 4;
        $lastError   = 'tidak diketahui';
        $lastStatus  = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $resp = Http::withHeaders([
                    'x-api-key'         => $this->key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])->timeout(60)->post($this->base . '/messages', $payload);

                if ($resp->successful()) {
                    return $resp->json() ?? [];
                }

                $lastStatus = $resp->status();
                $json       = $resp->json() ?? [];
                $lastError  = data_get($json, 'error.message', 'HTTP ' . $lastStatus);

                // Transient → tunggu lalu coba lagi (hormati Retry-After bila ada).
                if (in_array($lastStatus, self::RETRYABLE, true) && $attempt < $maxAttempts) {
                    $retryAfter = (int) $resp->header('retry-after');
                    $sleepMs    = $retryAfter > 0 ? $retryAfter * 1000 : 800 * $attempt;
                    usleep(min($sleepMs, 8000) * 1000);
                    continue;
                }

                Log::warning('Claude API gagal', ['status' => $lastStatus, 'error' => $lastError]);
                return ['_error' => $lastError, '_status' => $lastStatus];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt < $maxAttempts) {
                    usleep(800 * $attempt * 1000);
                    continue;
                }
                Log::warning('Claude API exception: ' . $lastError);
                return ['_error' => $lastError, '_status' => null];
            }
        }

        return ['_error' => $lastError, '_status' => $lastStatus];
    }
}
