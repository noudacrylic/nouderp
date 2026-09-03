<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmWebhookEvent;
use App\Modules\CRM\Services\IncomingWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Penerima webhook chat. Tanda tangannya sudah diperiksa middleware.
 *
 * SATU ATURAN YANG MENENTUKAN SELURUH BENTUK KELAS INI: **balas 200, hampir
 * apa pun yang terjadi.** Penyedia mematikan endpoint webhook secara otomatis
 * setelah gagal beruntun (`failure_count` / `disabled_at` di koleksi mereka).
 * Jadi payload yang tak kami pahami harus dicatat lalu diakui, bukan dibalas
 * 500 — kalau tidak, satu bentuk pesan aneh bisa mematikan seluruh saluran
 * masuk, diam-diam, dan baru ketahuan saat pelanggan mengeluh tak dibalas.
 *
 * Yang TIDAK dibalas 200 hanya kegagalan tanda tangan (di middleware): itu
 * bukan kiriman kami yang gagal diproses, melainkan kiriman yang bukan milik
 * kami sama sekali.
 */
class CrmWebhookController extends Controller
{
    public function __construct(private IncomingWebhookService $masuk)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = (array) $request->json()->all();

        $key = $this->idempotencyKey($request, $payload);

        // Kiriman ulang (balasan 200 kami terlambat, atau vendor mengulang):
        // akui, jangan kerjakan dua kali.
        $event = CrmWebhookEvent::catatBaru(
            $key,
            $payload['event_id'] ?? null,
            $payload['event_type'] ?? null,
            $payload
        );

        if (! $event) {
            return response()->json(['success' => true, 'duplicate' => true]);
        }

        try {
            $this->masuk->tangani($payload);
            $event->tandaiSelesai();
        } catch (\Throwable $e) {
            // Dicatat di barisnya sendiri supaya bisa diputar ulang belakangan,
            // lalu tetap diakui 200 — lihat catatan kelas di atas.
            $event->tandaiGagal($e->getMessage());

            Log::error('[CRM] gagal memproses webhook', [
                'event_type' => $payload['event_type'] ?? null,
                'event_id'   => $payload['event_id'] ?? null,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Kunci idempoten. Vendor mengirimkannya di header
     * (`<message_id>_<event_type>_<menit>`); bila tidak ada, kami susun sendiri
     * dari isi payload supaya penangkal duplikat tidak pernah bergantung pada
     * kebaikan hati pengirim.
     */
    private function idempotencyKey(Request $request, array $payload): string
    {
        $header = trim((string) $request->header('X-Webhook-Idempotency-Key', ''));

        if ($header !== '') {
            return $header;
        }

        $eventId = $payload['event_id'] ?? null;

        if ($eventId) {
            return 'evt:' . $eventId;
        }

        // Jalan terakhir: sidik jari isi. Dua kiriman identik pada menit yang
        // sama diperlakukan sebagai satu — itu memang yang diinginkan.
        return 'hash:' . hash('sha256', json_encode([
            $payload['event_type'] ?? null,
            data_get($payload, 'data.message_id'),
            substr((string) ($payload['timestamp'] ?? ''), 0, 16),
        ]));
    }
}
