<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmWebhookEvent;
use App\Modules\CRM\Services\WahaCerminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Penerima webhook WAHA — cermin chat nomor utama. Tokennya sudah diperiksa
 * middleware.
 *
 * Aturannya sama dengan penerima webhook chat resmi: **balas 200 hampir apa
 * pun yang terjadi.** WAHA mengulang kiriman yang gagal lalu berhenti setelah
 * batas percobaan; satu bentuk pesan yang tak kami pahami tidak boleh
 * mematikan seluruh cermin diam-diam. Payloadnya sudah tersimpan di
 * `crm_webhook_events`, jadi yang gagal selalu bisa diputar ulang.
 */
class WahaWebhookController extends Controller
{
    /**
     * Awalan `event_type` untuk baris cermin. WAJIB dipakai — pemeriksa
     * kesehatan webhook resmi (WebhookHealthService::sepi) menyimpulkan
     * "jalur resmi hidup" dari adanya baris baru di tabel ini, dan tanpa
     * penanda ini cermin WAHA akan terus-menerus membuktikan kesehatan jalur
     * yang justru sedang mati.
     */
    public const PREFIX = 'waha.';

    public function __construct(private WahaCerminService $cermin)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = (array) $request->json()->all();

        $event = CrmWebhookEvent::catatBaru(
            $this->idempotencyKey($request, $payload),
            null,   // id WAHA bukan UUID; kolom event_id sengaja dibiarkan kosong
            self::PREFIX . ($payload['event'] ?? 'unknown'),
            $payload
        );

        if (! $event) {
            return response()->json(['success' => true, 'duplicate' => true]);
        }

        try {
            $this->cermin->tangani($payload);
            $event->tandaiSelesai();
        } catch (\Throwable $e) {
            $event->tandaiGagal($e->getMessage());

            Log::error('[CRM] gagal memproses webhook WAHA', [
                'event'   => $payload['event'] ?? null,
                'session' => $payload['session'] ?? null,
                'error'   => $e->getMessage(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Kunci idempoten. WAHA mengirim id peristiwanya sendiri di badan (`id`)
     * dan mengulangnya di header `X-Webhook-Request-Id` saat mencoba ulang.
     *
     * Diberi awalan 'waha:' supaya tak mungkin bertabrakan dengan kunci dari
     * webhook vendor resmi — keduanya berbagi satu kolom unik, dan tabrakan di
     * situ akan membuang pesan yang sah sebagai "duplikat".
     */
    private function idempotencyKey(Request $request, array $payload): string
    {
        $id = trim((string) ($payload['id'] ?? $request->header('X-Webhook-Request-Id', '')));

        if ($id !== '') {
            return 'waha:' . $id;
        }

        // Jalan terakhir: sidik jari peristiwa + id pesan di dalamnya.
        return 'waha:hash:' . hash('sha256', json_encode([
            $payload['event'] ?? null,
            $payload['session'] ?? null,
            data_get($payload, 'payload.id'),
        ]));
    }
}
