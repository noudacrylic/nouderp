<?php

namespace App\Modules\CRM\Http\Middleware;

use App\Models\CrmSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga webhook WAHA. Token rahasia di PATH, plus HMAC bila dipasang.
 *
 * KENAPA TOKEN, BUKAN HMAC SAJA — WAHA hanya menandatangani webhook bila
 * `hmac.key` diisi pada konfigurasi sesinya, dan mengisinya berarti menyentuh
 * (dan me-restart) sesi nomor utama yang baru saja di-scan. Token acak 40
 * karakter adalah penjaga yang sudah dipakai ERP ini untuk Telegram & QRISLY,
 * dan di sini ia lebih kuat dari biasanya: WAHA memanggil ERP lewat localhost,
 * tidak pernah menyeberangi internet.
 *
 * Begitu `hmac.key` dipasang di sesi, isikan rahasianya di Pengaturan dan
 * tanda tangannya IKUT diperiksa — token tetap wajib. Sengaja bertumpuk, bukan
 * berganti: di sini tidak ada alasan melonggarkan satu penjaga saat penjaga
 * kedua datang.
 *
 * ⚠️ WAHA memakai **SHA-512** untuk `X-Webhook-Hmac`, bukan SHA-256 seperti
 * api.co.id, dan algoritmanya disebut di header `X-Webhook-Hmac-Algorithm`.
 * Menyalin begitu saja penjaga webhook sebelah akan menolak semua kiriman yang
 * sah, dengan gejala "webhook diam tanpa sebab".
 */
class VerifyWahaWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $setting = CrmSetting::for('waha');

        $token = (string) ($setting->config['webhook_url_token'] ?? '');
        $given = (string) $request->route('token');

        if ($token === '') {
            Log::error('[CRM] webhook WAHA ditolak: URL bertoken belum dibuat — buka Pengaturan → WhatsApp Self-Host.');

            return response()->json(['error' => 'webhook not configured'], 503);
        }

        if ($given === '' || ! hash_equals($token, $given)) {
            Log::warning('[CRM] webhook WAHA dengan token salah ditolak.');

            return response()->json(['error' => 'invalid token'], 403);
        }

        $secret = (string) ($setting->config['webhook_hmac'] ?? '');

        if ($secret !== '' && ! $this->hmacCocok($request, $secret)) {
            Log::warning('[CRM] webhook WAHA dengan tanda tangan salah ditolak.');

            return response()->json(['error' => 'invalid signature'], 403);
        }

        return $next($request);
    }

    /**
     * Tanda tangan dihitung atas RAW body. Encode ulang hasil json_decode akan
     * mengubah urutan kunci dan escape unicode, dan tanda tangan yang sah pun
     * tidak akan pernah cocok lagi.
     */
    private function hmacCocok(Request $request, string $secret): bool
    {
        $diberi = (string) $request->header('X-Webhook-Hmac', '');

        if ($diberi === '') {
            return false;
        }

        $algo = strtolower((string) $request->header('X-Webhook-Hmac-Algorithm', 'sha512'));

        if (! in_array($algo, ['sha512', 'sha256'], true)) {
            return false;
        }

        return hash_equals(hash_hmac($algo, $request->getContent(), $secret), $diberi);
    }
}
