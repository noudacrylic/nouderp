<?php

namespace App\Modules\CRM\Http\Middleware;

use App\Models\CrmSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifikasi tanda tangan webhook chat (HMAC-SHA256).
 *
 * DUA HAL YANG WAJIB DIPERTAHANKAN:
 *
 * 1. Tanda tangan dihitung atas **RAW body**, bukan hasil json_decode lalu
 *    json_encode ulang. Encode ulang mengubah urutan kunci, spasi, dan escape
 *    unicode — hasilnya tanda tangan yang sah pun tidak akan pernah cocok, dan
 *    gejalanya "semua webhook ditolak tanpa sebab yang terlihat".
 *
 * 2. Kunci kosong = TOLAK, bukan lolos. Endpoint ini menulis percakapan dan
 *    mengunduh berkas dari URL yang disebut pengirim; kalau boleh dipanggil
 *    tanpa tanda tangan, siapa pun bisa mengarang percakapan di ERP.
 */
class VerifyCrmWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) (CrmSetting::for('apicoid')->webhook_secret ?? '');

        if ($secret === '') {
            Log::error('[CRM] webhook ditolak: webhook_secret belum diisi di Pengaturan.');

            return response()->json(['error' => 'webhook secret not configured'], 503);
        }

        $signature = (string) $request->header('X-Webhook-Signature', '');

        if ($signature === '') {
            Log::warning('[CRM] webhook tanpa tanda tangan ditolak.');

            return response()->json(['error' => 'missing signature'], 401);
        }

        // getContent() = badan permintaan apa adanya, byte per byte.
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        // Sebagian pengirim memberi awalan 'sha256='; buang sebelum dibandingkan.
        $given = str_contains($signature, '=')
            ? substr($signature, strrpos($signature, '=') + 1)
            : $signature;

        if (! hash_equals($expected, $given)) {
            Log::warning('[CRM] webhook dengan tanda tangan salah ditolak', [
                'delivery' => $request->header('X-Webhook-Delivery'),
            ]);

            return response()->json(['error' => 'invalid signature'], 403);
        }

        return $next($request);
    }
}
