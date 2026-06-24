<?php

namespace App\Http\Controllers;

use App\Models\TelegramSetting;
use App\Models\User;
use App\Modules\Notifications\Services\TelegramNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook publik "Noud Bot" (server-to-server, tanpa CSRF/auth).
 *
 * Tugas utama: menangkap chat_id saat user menekan Start lewat deep-link
 * t.me/<bot>?start=<token>. Token dicocokkan ke users.telegram_link_token →
 * simpan telegram_chat_id, kosongkan token, balas konfirmasi.
 *
 * Keamanan: segmen {secret} pada URL harus sama dengan telegram_settings.webhook_secret
 * (Telegram juga mengirim header X-Telegram-Bot-Api-Secret-Token = secret yang sama).
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request, string $secret, TelegramNotifier $telegram)
    {
        $setting = TelegramSetting::current();

        // Tolak diam-diam bila belum dikonfigurasi atau secret tidak cocok.
        if (! $setting || empty($setting->webhook_secret)
            || ! hash_equals($setting->webhook_secret, $secret)) {
            return response()->json(['ok' => false], 403);
        }

        $message = $request->input('message') ?? $request->input('edited_message');
        $chatId  = data_get($message, 'chat.id');
        $text    = trim((string) data_get($message, 'text', ''));

        // Hanya proses perintah /start (linking). Update lain diabaikan (return 200).
        if ($chatId && str_starts_with($text, '/start')) {
            $this->handleStart($telegram, (string) $chatId, $text);
        }

        return response()->json(['ok' => true]);
    }

    private function handleStart(TelegramNotifier $telegram, string $chatId, string $text): void
    {
        $parts = preg_split('/\s+/', $text, 2);
        $token = isset($parts[1]) ? trim($parts[1]) : '';

        if ($token === '') {
            $telegram->send($chatId,
                "👋 <b>Noud Bot</b>\n\nUntuk menghubungkan akun, buka menu "
                . "<b>Telegram</b> di aplikasi lalu tekan tombol <i>Hubungkan</i>.");
            return;
        }

        $user = User::where('telegram_link_token', $token)->first();

        if (! $user) {
            $telegram->send($chatId,
                "⚠️ Tautan kedaluwarsa atau tidak valid. Silakan buka aplikasi dan "
                . "tekan <i>Hubungkan</i> lagi untuk mendapatkan tautan baru.");
            return;
        }

        try {
            $user->telegram_chat_id    = $chatId;
            $user->telegram_link_token = null;
            $user->save();
        } catch (\Throwable $e) {
            Log::warning('Telegram link gagal: ' . $e->getMessage());
            $telegram->send($chatId, "❌ Gagal menghubungkan akun. Coba lagi sebentar.");
            return;
        }

        $nama = $user->name ?: 'Pengguna';
        $telegram->send($chatId,
            "✅ <b>Berhasil terhubung!</b>\n\nHalo {$nama}, akun Anda kini menerima "
            . "notifikasi dari Noud ERP di sini.");
    }
}
