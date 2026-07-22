<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TelegramSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Settings → Integrasi → Telegram ("Noud Bot").
 * Konfigurasi token/username bot, pasang webhook, dan hubungkan akun pengguna.
 */
class TelegramSettingController extends Controller
{
    public function edit()
    {
        $setting = TelegramSetting::singleton();
        $user    = auth()->user();

        $webhookUrl = $setting->webhook_secret
            ? url('/telegram/webhook/' . $setting->webhook_secret)
            : null;

        // Info bot & webhook (best-effort; aman bila token belum diisi).
        $botInfo     = null;
        $webhookInfo = null;
        if (! empty($setting->bot_token)) {
            $botInfo     = $this->api($setting, 'getMe');
            $webhookInfo = $this->api($setting, 'getWebhookInfo');
        }

        return view('erp.settings.telegram.edit', compact(
            'setting', 'user', 'webhookUrl', 'botInfo', 'webhookInfo'
        ));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'bot_token'     => 'nullable|string|max:255',
            'bot_username'  => 'nullable|string|max:64',
            'admin_chat_id' => 'nullable|string|max:64',
            'is_active'     => 'nullable|boolean',
        ]);

        $setting = TelegramSetting::singleton();
        $setting->fill([
            'bot_token'     => $data['bot_token'] ?: null,
            'bot_username'  => $data['bot_username'] ? ltrim($data['bot_username'], '@') : null,
            'admin_chat_id' => $data['admin_chat_id'] ?: null,
            'is_active'     => (bool) ($data['is_active'] ?? false),
        ]);
        if (! empty($setting->bot_token)) {
            $setting->ensureWebhookSecret(); // siapkan secret untuk URL webhook
        }
        $setting->save();

        return redirect()->route('settings.telegram.edit')
            ->with('success', 'Pengaturan Telegram disimpan.');
    }

    /** Pasang/refresh webhook ke Telegram. */
    public function setWebhook()
    {
        $setting = TelegramSetting::singleton();
        if (empty($setting->bot_token)) {
            return back()->with('error', 'Isi & simpan Bot Token dulu.');
        }

        $secret = $setting->ensureWebhookSecret();
        $url    = url('/telegram/webhook/' . $secret);

        $resp = $this->api($setting, 'setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);

        if (data_get($resp, 'ok')) {
            return back()->with('success', 'Webhook terpasang: ' . $url);
        }
        return back()->with('error', 'Gagal memasang webhook: ' . data_get($resp, 'description', 'tidak diketahui'));
    }

    /** Lepas webhook (mis. saat pindah domain / nonaktif). */
    public function deleteWebhook()
    {
        $setting = TelegramSetting::singleton();
        if (empty($setting->bot_token)) {
            return back()->with('error', 'Bot Token belum diisi.');
        }
        $resp = $this->api($setting, 'deleteWebhook', ['drop_pending_updates' => false]);

        return data_get($resp, 'ok')
            ? back()->with('success', 'Webhook dilepas.')
            : back()->with('error', 'Gagal melepas webhook: ' . data_get($resp, 'description', 'tidak diketahui'));
    }

    /** Hubungkan akun user yang sedang login: buat token & arahkan ke deep-link bot. */
    public function link()
    {
        $setting = TelegramSetting::singleton();
        $username = $setting->usernameClean();

        if (empty($setting->bot_token) || empty($username)) {
            return back()->with('error', 'Bot Token & Username harus diisi dulu sebelum menghubungkan akun.');
        }

        $user = auth()->user();
        $user->telegram_link_token = Str::random(32);
        $user->save();

        // Buka Telegram; user tekan Start → webhook menyimpan chat_id.
        return redirect()->away("https://t.me/{$username}?start={$user->telegram_link_token}");
    }

    /** Putuskan akun Telegram user yang sedang login. */
    public function unlink()
    {
        $user = auth()->user();
        $user->telegram_chat_id    = null;
        $user->telegram_link_token = null;
        $user->save();

        return back()->with('success', 'Akun Telegram diputuskan.');
    }

    /** Panggil Bot API; return array hasil (atau ['ok'=>false] bila error). */
    private function api(TelegramSetting $setting, string $method, array $params = []): array
    {
        try {
            $resp = Http::timeout(8)
                ->asJson()
                ->post("https://api.telegram.org/bot{$setting->bot_token}/{$method}", $params);
            return $resp->json() ?: ['ok' => false, 'description' => 'respons kosong'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }
}
