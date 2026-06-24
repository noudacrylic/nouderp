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

    /**
     * Kirim ke semua penanggung jawab approval (super_admin/admin yang sudah
     * link Telegram) + admin_chat_id fallback (grup) bila diset.
     */
    public function notifyApprovers(string $text, array $opts = []): int
    {
        $sent = 0;

        $chatIds = User::whereIn('role', ['super_admin', 'admin'])
            ->whereNotNull('telegram_chat_id')
            ->pluck('telegram_chat_id')
            ->all();

        if ($this->adminChatId) {
            $chatIds[] = $this->adminChatId;
        }

        foreach (array_unique(array_filter($chatIds)) as $chatId) {
            if ($this->send($chatId, $text, $opts)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Kirim ke user tertentu (mis. karyawan pemohon) bila sudah link Telegram. */
    public function notifyUser(?User $user, string $text, array $opts = []): bool
    {
        return $user && $this->send($user->telegram_chat_id, $text, $opts);
    }
}
