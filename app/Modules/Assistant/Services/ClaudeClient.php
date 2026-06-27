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

    /**
     * Kirim request ke /messages. Return respons terdekode, atau ['_error' => '...'].
     */
    public function messages(array $payload): array
    {
        if (! $this->enabled()) {
            return ['_error' => 'ANTHROPIC_API_KEY belum diatur di server (.env).'];
        }

        try {
            $resp = Http::withHeaders([
                'x-api-key'         => $this->key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post($this->base . '/messages', $payload);

            $json = $resp->json() ?? [];

            if (! $resp->successful()) {
                $msg = data_get($json, 'error.message', 'HTTP ' . $resp->status());
                Log::warning('Claude API gagal', ['status' => $resp->status(), 'error' => $msg]);
                return ['_error' => $msg];
            }

            return $json;
        } catch (\Throwable $e) {
            Log::warning('Claude API exception: ' . $e->getMessage());
            return ['_error' => $e->getMessage()];
        }
    }
}
