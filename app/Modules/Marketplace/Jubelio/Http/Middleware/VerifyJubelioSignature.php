<?php

namespace App\Modules\Marketplace\Jubelio\Http\Middleware;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi webhook Jubelio via TOKEN RAHASIA DI URL.
 *
 * Latar: signature bawaan Jubelio (header "sign") TIDAK dapat diverifikasi dari sisi kita —
 * formula signing Jubelio menyimpang dari dokumentasinya (diuji ribuan kombinasi hash/HMAC
 * terhadap payload asli, tak ada yang cocok meski secret sudah benar). Karena itu keamanan
 * dialihkan ke token rahasia pada query string URL webhook.
 *
 * Konfigurasi URL webhook di Jubelio (token di PATH — Jubelio membuang query string):
 *   https://erp.noudakrilik.com/jubelio/webhook/salesorder/<webhook_secret>
 *   https://erp.noudakrilik.com/jubelio/webhook/salesreturn/<webhook_secret>
 *   https://erp.noudakrilik.com/jubelio/webhook/stock/<webhook_secret>
 *
 * Aman karena: (1) token dibandingkan constant-time; (2) handler hanya memicu pemrosesan
 * idempotent yang menarik data OTORITATIF dari API Jubelio (payload tak dipercaya selain ID);
 * (3) endpoint berada di balik Cloudflare. Bila webhook_secret kosong, verifikasi dilewati
 * (mode dev/localhost).
 */
class VerifyJubelioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = JubelioSetting::singleton()->webhook_secret;

        if (empty($secret)) {
            return $next($request);
        }

        $token = $request->route('token') ?: $request->query('token') ?: $request->header('x-webhook-token');
        if (!is_string($token) || $token === '' || !hash_equals($secret, $token)) {
            Log::warning('Jubelio webhook ditolak: token URL salah/kosong', ['path' => $request->path()]);
            return response()->json(['error' => 'invalid token'], 403);
        }

        return $next($request);
    }
}
