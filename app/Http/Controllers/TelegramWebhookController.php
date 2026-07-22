<?php

namespace App\Http\Controllers;

use App\Models\TelegramSetting;
use App\Models\User;
use App\Modules\Assistant\Services\AiAccountantService;
use App\Modules\Notifications\Services\TelegramNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        // Tombol inline (✅ Sudah Masuk / ❌ Batalkan) pada eskalasi pembayaran.
        if ($callback = $request->input('callback_query')) {
            $this->handleCallback($telegram, $callback);
            return response()->json(['ok' => true]);
        }

        $message = $request->input('message') ?? $request->input('edited_message');
        $chatId  = data_get($message, 'chat.id');
        // Teks pesan, atau caption bila berupa foto.
        $text    = trim((string) (data_get($message, 'text') ?? data_get($message, 'caption', '')));

        // Foto struk (ambil ukuran terbesar = elemen terakhir array photo).
        $photos      = data_get($message, 'photo', []);
        $photoFileId = is_array($photos) && $photos ? data_get(end($photos), 'file_id') : null;

        if ($chatId && str_starts_with($text, '/start')) {
            // /start <token> → linking akun.
            $this->handleStart($telegram, (string) $chatId, $text);
        } elseif ($chatId) {
            // Pesan lain (teks / foto struk) → asisten pencatat keuangan.
            $this->handleMessage($telegram, (string) $chatId, $text, $photoFileId);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Pesan masuk selain /start → diteruskan ke AiAccountantService.
     * Guard: hanya user ter-link & ber-role admin/owner yang boleh mencatat transaksi.
     */
    private function handleMessage(TelegramNotifier $telegram, string $chatId, string $text, ?string $photoFileId = null): void
    {
        $user = User::where('telegram_chat_id', $chatId)->first();

        if (! $user) {
            $telegram->send($chatId,
                '⚠️ Akun Telegram ini belum terhubung ke Noud ERP. Buka aplikasi → menu '
                . '<b>Telegram</b> → <i>Hubungkan</i>.');
            return;
        }

        if (! $user->canManageUsers()) {
            $telegram->send($chatId, '⛔ Maaf, hanya admin/pemilik yang bisa mencatat transaksi lewat bot.');
            return;
        }

        // Foto struk → unduh & teruskan ke AI (vision).
        $image = $photoFileId ? $telegram->getFileBase64($photoFileId) : null;

        if ($text === '' && ! $image) {
            $telegram->send($chatId,
                'Kirim perintah teks (mis. <i>catat pengeluaran bensin 50rb dari kas</i>) '
                . 'atau <b>foto struk</b> untuk dicatat otomatis.');
            return;
        }

        try {
            // Webhook tanpa sesi → login user utk request ini saja (created_by, dll. ikut benar).
            Auth::login($user);
            $reply = app(AiAccountantService::class)->handle($user, $chatId, $text, $image);
        } catch (\Throwable $e) {
            Log::warning('AiAccountant gagal: ' . $e->getMessage());
            $reply = '❌ Terjadi kesalahan: ' . $e->getMessage();
        }

        $telegram->send($chatId, $reply);
    }

    /**
     * Callback tombol inline eskalasi pembayaran toko online.
     * callback_data: "wp:confirm:{id}" | "wp:cancel:{id}". Hanya admin/owner ter-link.
     */
    private function handleCallback(TelegramNotifier $telegram, array $callback): void
    {
        $callbackId = (string) data_get($callback, 'id');
        $chatId     = (string) data_get($callback, 'message.chat.id');
        $messageId  = (int) data_get($callback, 'message.message_id');
        $data       = (string) data_get($callback, 'data');

        $user = User::where('telegram_chat_id', $chatId)->first();
        if (! $user || ! $user->canManageUsers()) {
            $telegram->answerCallback($callbackId, '⛔ Hanya admin/pemilik yang bisa mengonfirmasi.', true);
            return;
        }

        if (! preg_match('/^wp:(confirm|cancel):(\d+)(?::(\d+))?$/', $data, $m)) {
            $telegram->answerCallback($callbackId);
            return;
        }

        $action     = $m[1];
        $id         = (int) $m[2];
        $accountKey = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;

        $wp = \App\Models\WebPayment::find($id);
        if (! $wp) {
            $telegram->answerCallback($callbackId, 'Pembayaran tidak ditemukan.', true);
            return;
        }

        if (! $wp->isOpen()) {
            $telegram->answerCallback($callbackId, 'Sudah diproses sebelumnya.', true);
            $telegram->editMessageText($chatId, $messageId,
                "ℹ️ Pembayaran ini sudah <b>{$wp->statusLabel()['label']}</b>.",
                ['reply_markup' => json_encode(['inline_keyboard' => []])]);
            return;
        }

        $service = app(\App\Modules\Sales\Services\WebPaymentService::class);

        try {
            // Webhook tanpa sesi → login user untuk request ini (created_by benar).
            Auth::login($user);

            if ($action === 'confirm') {
                $cashId = $accountKey !== null
                    ? \App\Models\PaymentSetting::singleton()->cashIdFor($accountKey)
                    : null;
                $service->confirm($id, 'telegram', 'telegram:' . $user->id, $user->id, $cashId);
                $result = "✅ Ditandai <b>LUNAS</b> oleh " . e($user->name) . ".";
            } else {
                $service->expire($id, \App\Models\WebPayment::STATUS_CANCELLED,
                    'Dibatalkan via Telegram oleh ' . $user->name);
                $result = "❌ Order <b>DIBATALKAN</b> oleh " . e($user->name) . ".";
            }
        } catch (\Throwable $e) {
            Log::warning('WebPayment callback gagal: ' . $e->getMessage());
            $telegram->answerCallback($callbackId, '❌ Gagal: ' . $e->getMessage(), true);
            return;
        }

        $telegram->answerCallback($callbackId, 'Tersimpan.');
        $telegram->editMessageText($chatId, $messageId, $result,
            ['reply_markup' => json_encode(['inline_keyboard' => []])]);
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
