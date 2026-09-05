<?php

namespace App\Modules\CRM\Http\Middleware;

use App\Models\CrmSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifikasi keaslian webhook chat. Dua jalur, dan hanya dua.
 *
 * A. **HMAC-SHA256** atas RAW body (`X-Webhook-Signature`) — dipakai bila
 *    `webhook_secret` terisi. Ini jalur utama dan yang paling kuat.
 *
 * B. **Token rahasia di PATH** (`/crm/webhook/{token}`) — dipakai bila
 *    `webhook_secret` KOSONG. Ada karena api.co.id menandatangani webhook-nya
 *    tapi tidak memperlihatkan signing secret-nya di mana pun (dibuktikan
 *    5 Sep 2026: 63 kombinasi kunci × bentuk pesan diuji atas payload nyata,
 *    tak satu pun cocok). Tanpa jalur ini pilihannya cuma menolak semua webhook
 *    atau menerima payload tanpa verifikasi — keduanya buruk. Token acak 40
 *    karakter di dalam URL HTTPS adalah pola yang sudah dipakai ERP ini untuk
 *    Telegram dan QRISLY.
 *
 * TIGA HAL YANG WAJIB DIPERTAHANKAN:
 *
 * 1. Tanda tangan dihitung atas **RAW body**, bukan hasil json_decode lalu
 *    json_encode ulang. Encode ulang mengubah urutan kunci, spasi, dan escape
 *    unicode — hasilnya tanda tangan yang sah pun tidak akan pernah cocok, dan
 *    gejalanya "semua webhook ditolak tanpa sebab yang terlihat".
 *
 * 2. **Token path TIDAK boleh menjadi jalan pintas atas HMAC.** Begitu
 *    `webhook_secret` terisi, HMAC yang menentukan — token benar sekalipun
 *    tidak menolong. Kalau tidak, penjaga yang lebih kuat bisa dilewati hanya
 *    dengan menebak URL yang mungkin bocor lewat log proxy.
 *
 * 3. Tidak ada penjaga sama sekali = TOLAK, bukan lolos. Endpoint ini menulis
 *    percakapan dan mengunduh berkas dari URL yang disebut pengirim; kalau
 *    boleh dipanggil tanpa verifikasi, siapa pun bisa mengarang percakapan.
 */
class VerifyCrmWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $setting = CrmSetting::for('apicoid');
        $secret  = (string) ($setting->webhook_secret ?? '');

        if ($secret !== '') {
            return $this->viaHmac($request, $next, $secret);
        }

        return $this->viaToken($request, $next, $setting);
    }

    /** Jalur A: tanda tangan HMAC-SHA256 atas raw body. */
    private function viaHmac(Request $request, Closure $next, string $secret): Response
    {
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

    /** Jalur B: token rahasia di path, dipakai selama signing secret vendor belum ada. */
    private function viaToken(Request $request, Closure $next, CrmSetting $setting): Response
    {
        $expected = (string) ($setting->config['webhook_url_token'] ?? '');
        $given    = (string) $request->route('token');

        if ($expected === '') {
            Log::error('[CRM] webhook ditolak: tidak ada penjaga sama sekali — isi Rahasia Webhook (HMAC) atau buat URL bertoken di Pengaturan CRM.');

            return response()->json(['error' => 'webhook verification not configured'], 503);
        }

        if ($given === '' || ! hash_equals($expected, $given)) {
            Log::warning('[CRM] webhook dengan token path salah ditolak', [
                'delivery' => $request->header('X-Webhook-Delivery'),
            ]);

            return response()->json(['error' => 'invalid token'], 403);
        }

        return $next($request);
    }
}
