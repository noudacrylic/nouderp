<?php

namespace App\Modules\Notifications\Services;

use App\Models\TelegramSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Noud Bot" — pengirim notifikasi Telegram (outbound sendMessage).
 *
 * MODULAR & reusable (Fase 2: foto nota → input AI). Outbound TIDAK butuh
 * Cloudflare Tunnel. Tanpa token (config services.telegram.token kosong) seluruh
 * method NO-OP aman — tidak pernah melempar exception ke alur bisnis.
 *
 * Inbound (tangkap chat_id via getUpdates long-polling) BELUM diimplementasi —
 * lihat memori project_dashboard_karyawan_2026_06_23.
 */
class TelegramNotifier
{
    private ?string $token;
    private ?string $adminChatId;

    public function __construct()
    {
        // Sumber utama: pengaturan DB (Settings → Integrasi → Telegram).
        // Fallback ke config/.env supaya konfigurasi lama tetap jalan.
        $setting = TelegramSetting::current();

        $this->token = $setting && $setting->is_active
            ? ($setting->bot_token ?: config('services.telegram.token'))
            : config('services.telegram.token');

        $this->adminChatId = ($setting?->admin_chat_id)
            ?: config('services.telegram.admin_chat_id');
    }

    public function enabled(): bool
    {
        return ! empty($this->token);
    }

    /** Kirim pesan ke satu chat. Return true bila terkirim. Tidak pernah throw. */
    public function send(?string $chatId, string $text, array $opts = []): bool
    {
        if (! $this->enabled() || empty($chatId)) {
            return false;
        }

        try {
            $resp = Http::timeout(8)->post("https://api.telegram.org/bot{$this->token}/sendMessage", array_merge([
                'chat_id'                  => $chatId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ], $opts));
            return $resp->successful();
        } catch (\Throwable $e) {
            Log::warning('TelegramNotifier send gagal: ' . $e->getMessage());
            return false;
        }
    }

    /** Daftar chat_id penanggung jawab approval (admin/owner ter-link) + fallback grup. */
    public function approverChatIds(): array
    {
        $chatIds = User::whereIn('role', ['super_admin', 'admin'])
            ->whereNotNull('telegram_chat_id')
            ->pluck('telegram_chat_id')
            ->all();

        if ($this->adminChatId) {
            $chatIds[] = $this->adminChatId;
        }

        return array_values(array_unique(array_filter($chatIds)));
    }

    /**
     * Kirim ke semua penanggung jawab approval (super_admin/admin yang sudah
     * link Telegram) + admin_chat_id fallback (grup) bila diset.
     */
    public function notifyApprovers(string $text, array $opts = []): int
    {
        $sent = 0;
        foreach ($this->approverChatIds() as $chatId) {
            if ($this->send($chatId, $text, $opts)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Kirim pesan & kembalikan message_id (untuk diedit belakangan). Null bila gagal. */
    public function sendAndGetMessageId(?string $chatId, string $text, array $opts = []): ?int
    {
        if (! $this->enabled() || empty($chatId)) {
            return null;
        }

        try {
            $resp = Http::timeout(8)->post("https://api.telegram.org/bot{$this->token}/sendMessage", array_merge([
                'chat_id'                  => $chatId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ], $opts));
            return $resp->successful() ? (int) data_get($resp->json(), 'result.message_id') : null;
        } catch (\Throwable $e) {
            Log::warning('TelegramNotifier sendAndGetMessageId gagal: ' . $e->getMessage());
            return null;
        }
    }

    /** Edit teks pesan (mis. hapus tombol setelah order dikonfirmasi). Tidak pernah throw. */
    public function editMessageText(?string $chatId, ?int $messageId, string $text, array $opts = []): bool
    {
        if (! $this->enabled() || empty($chatId) || empty($messageId)) {
            return false;
        }

        try {
            $resp = Http::timeout(8)->post("https://api.telegram.org/bot{$this->token}/editMessageText", array_merge([
                'chat_id'                  => $chatId,
                'message_id'               => $messageId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ], $opts));
            return $resp->successful();
        } catch (\Throwable $e) {
            Log::warning('TelegramNotifier editMessageText gagal: ' . $e->getMessage());
            return false;
        }
    }

    /** Jawab callback_query (hilangkan loading di tombol inline). Tidak pernah throw. */
    public function answerCallback(string $callbackId, string $text = '', bool $alert = false): bool
    {
        if (! $this->enabled() || $callbackId === '') {
            return false;
        }

        try {
            $resp = Http::timeout(8)->post("https://api.telegram.org/bot{$this->token}/answerCallbackQuery", array_filter([
                'callback_query_id' => $callbackId,
                'text'              => $text !== '' ? $text : null,
                'show_alert'        => $alert,
            ], fn ($v) => $v !== null));
            return $resp->successful();
        } catch (\Throwable $e) {
            Log::warning('TelegramNotifier answerCallback gagal: ' . $e->getMessage());
            return false;
        }
    }

    /** Kirim ke user tertentu (mis. karyawan pemohon) bila sudah link Telegram. */
    public function notifyUser(?User $user, string $text, array $opts = []): bool
    {
        return $user && $this->send($user->telegram_chat_id, $text, $opts);
    }

    /**
     * Kirim file (dokumen) ke satu chat — mis. PDF Sales Order untuk diteruskan ke pembeli.
     * $bytes = isi file mentah. Return true bila terkirim. Tidak pernah throw.
     */
    public function sendDocument(?string $chatId, string $bytes, string $filename, string $caption = ''): bool
    {
        if (! $this->enabled() || empty($chatId) || $bytes === '') {
            return false;
        }

        try {
            $req = Http::timeout(30)
                ->attach('document', $bytes, $filename)
                ->post("https://api.telegram.org/bot{$this->token}/sendDocument", array_filter([
                    'chat_id'    => $chatId,
                    'caption'    => $caption !== '' ? $caption : null,
                    'parse_mode' => 'HTML',
                ]));
            return $req->successful();
        } catch (\Throwable $e) {
            Log::warning('TelegramNotifier sendDocument gagal: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Unduh file Telegram (mis. foto struk) → ['data' => base64, 'media_type' => '...'].
     * Null bila gagal. Dipakai asisten AI untuk membaca struk (vision).
     */
    public function getFileBase64(string $fileId): ?array
    {
        if (! $this->enabled() || $fileId === '') {
            return null;
        }

        try {
            $info = Http::timeout(10)->get("https://api.telegram.org/bot{$this->token}/getFile", [
                'file_id' => $fileId,
            ]);
            $path = data_get($info->json(), 'result.file_path');
            if (! $path) {
                return null;
            }

            $bin = Http::timeout(20)->get("https://api.telegram.org/file/bot{$this->token}/{$path}");
            if (! $bin->successful()) {
                return null;
            }

            $ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $media = match ($ext) {
                'png'  => 'image/png',
                'webp' => 'image/webp',
                'gif'  => 'image/gif',
                default => 'image/jpeg',
            };

            return ['data' => base64_encode($bin->body()), 'media_type' => $media];
        } catch (\Throwable $e) {
            Log::warning('TelegramNotifier getFile gagal: ' . $e->getMessage());
            return null;
        }
    }
}
