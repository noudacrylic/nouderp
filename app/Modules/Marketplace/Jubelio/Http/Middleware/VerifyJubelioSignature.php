<?php

namespace App\Modules\Marketplace\Jubelio\Http\Middleware;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifikasi signature webhook Jubelio.
 * Sesuai dokumentasi: signature = SHA256( stringify(payload) + secret_key ),
 * dikirim di header "webhook-signature".
 *
 * Bila webhook_secret belum diatur, verifikasi dilewati (mode dev/localhost) —
 * tetapi tetap aman karena route ini hanya memicu pemrosesan idempotent yang
 * sama dengan cron.
 */
class VerifyJubelioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = JubelioSetting::singleton()->webhook_secret;

        if (empty($secret)) {
            return $next($request);
        }

        $signature = $request->header('webhook-signature');
        if (empty($signature)) {
            Log::warning('Jubelio webhook tanpa signature');
            return response()->json(['error' => 'missing signature'], 403);
        }

        $expected = hash('sha256', $request->getContent() . $secret);

        if (!hash_equals($expected, $signature)) {
            Log::warning('Jubelio webhook signature salah');
            return response()->json(['error' => 'invalid signature'], 403);
        }

        return $next($request);
    }
}
