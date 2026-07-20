<?php

namespace App\Modules\Notifications\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Pengirim Web Push Notification ke PWA Karyawan (Push API + VAPID).
 *
 * Pola sama dengan TelegramNotifier: tanpa kunci VAPID (config services.webpush)
 * seluruh method NO-OP aman — tidak pernah melempar exception ke alur bisnis.
 *
 * Notifikasi muncul di HP karyawan seperti notif aplikasi (lewat service worker),
 * bahkan saat app ditutup. Android penuh dukung; iOS ≥16.4 hanya bila PWA sudah
 * di-"Add to Home Screen".
 */
class WebPushNotifier
{
    private ?string $publicKey;
    private ?string $privateKey;
    private string $subject;

    public function __construct()
    {
        $this->publicKey  = config('services.webpush.public_key');
        $this->privateKey = config('services.webpush.private_key');
        $this->subject    = config('services.webpush.subject') ?: 'mailto:admin@noudacrylic.com';

        // CATATAN openssl (Windows/XAMPP): operasi EC (ECDH enkripsi payload + tanda
        // tangan ES256 VAPID) butuh openssl menemukan openssl.cnf. Itu HARUS lewat env
        // var OPENSSL_CONF yang di-set SEBELUM PHP start (putenv saat runtime TIDAK
        // berpengaruh — openssl meng-init config saat startup). Di Linux/produksi
        // openssl sudah menemukan config default → tidak perlu apa-apa.
        // Dev Windows: jalankan `setx OPENSSL_CONF "C:\xampp\php\extras\ssl\openssl.cnf"`
        // (config services.webpush.openssl_conf hanya dokumentasi path-nya).
    }

    public function enabled(): bool
    {
        return ! empty($this->publicKey) && ! empty($this->privateKey);
    }

    /**
     * Kirim notifikasi ke semua perangkat seorang user (akun login karyawan).
     * Langganan yang sudah kedaluwarsa otomatis dibersihkan. Return jumlah terkirim.
     */
    public function notifyUser(?User $user, string $title, string $body, array $opts = []): int
    {
        if (! $user) {
            return 0;
        }

        $subs = PushSubscription::where('user_id', $user->id)->get();
        if ($subs->isEmpty()) {
            return 0;
        }

        return $this->sendToSubscriptions($subs, $title, $body, $opts);
    }

    /**
     * Kirim ke SEMUA user ERP (super_admin/admin/user) yang mengaktifkan notifikasi di
     * browser desktop — dipakai untuk memberi tahu tim packing saat ada pesanan masuk.
     *
     * Penargetan berdasar role: langganan karyawan (PWA /me) role='karyawan' dikirim untuk
     * absensi/izin lewat notifyUser; langganan ERP (role bukan 'karyawan') hanya dibuat
     * lewat toggle di aplikasi ERP yang satu-satunya fitur push-nya adalah notifikasi ini,
     * jadi "berlangganan di ERP" = "ingin notifikasi pesanan". Opt-in per perangkat.
     */
    public function notifyErpUsers(string $title, string $body, array $opts = []): int
    {
        $subs = PushSubscription::whereHas('user', function ($q) {
            $q->where('role', '!=', 'karyawan');
        })->get();

        if ($subs->isEmpty()) {
            return 0;
        }

        return $this->sendToSubscriptions($subs, $title, $body, $opts);
    }

    /**
     * @param  \Illuminate\Support\Collection<PushSubscription>  $subs
     */
    public function sendToSubscriptions($subs, string $title, string $body, array $opts = []): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => $this->subject,
                    'publicKey'  => $this->publicKey,
                    'privateKey' => $this->privateKey,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('WebPush init gagal: ' . $e->getMessage());
            return 0;
        }

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $opts['url'] ?? '/me',
            'tag'   => $opts['tag'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        $byEndpoint = [];
        foreach ($subs as $sub) {
            $byEndpoint[$sub->endpoint] = $sub;
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint'        => $sub->endpoint,
                        'publicKey'       => $sub->public_key,
                        'authToken'       => $sub->auth_token,
                        'contentEncoding' => $sub->content_encoding ?: 'aes128gcm',
                    ]),
                    $payload
                );
            } catch (\Throwable $e) {
                Log::warning('WebPush queue gagal: ' . $e->getMessage());
            }
        }

        $sent = 0;
        try {
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();
                if ($report->isSuccess()) {
                    $sent++;
                    continue;
                }
                // Langganan mati (404/410) → hapus supaya tidak terus dicoba.
                if ($report->isSubscriptionExpired() && isset($byEndpoint[$endpoint])) {
                    $byEndpoint[$endpoint]->delete();
                } else {
                    Log::info('WebPush gagal: ' . $report->getReason());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WebPush flush gagal: ' . $e->getMessage());
        }

        return $sent;
    }
}
