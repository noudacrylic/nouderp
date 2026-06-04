<?php

namespace App\Modules\Marketplace\Jubelio\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Endpoint webhook Jubelio (hybrid). Payload webhook hanya notifikasi — controller
 * memanggil JubelioOrderSyncService yang SAMA dengan cron (idempotent), bukan logika
 * terpisah. Selalu balas HTTP 200 untuk data-error agar Jubelio tidak retry terus.
 */
class JubelioWebhookController extends Controller
{
    public function __construct(protected JubelioOrderSyncService $sync) {}

    /** POST /jubelio/webhook/salesorder */
    public function salesOrder(Request $request): JsonResponse
    {
        $payload = $request->json()->all() ?: $request->all();
        $id = (int) ($payload['salesorder_id'] ?? 0);

        if ($id <= 0) {
            return response()->json(['ok' => false, 'error' => 'salesorder_id kosong'], 200);
        }

        try {
            $this->sync->syncOrderById($id);
            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            Log::error('Jubelio webhook salesorder error', ['salesorder_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /jubelio/webhook/salesreturn */
    public function salesReturn(Request $request): JsonResponse
    {
        try {
            $this->sync->syncReturns();
            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            Log::error('Jubelio webhook salesreturn error', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /jubelio/webhook/stock — diabaikan secara sengaja.
     * ERP adalah sumber kebenaran stok; webhook stok Jubelio tidak dipantulkan
     * balik (hindari loop). Hanya dicatat untuk audit.
     */
    public function stock(Request $request): JsonResponse
    {
        Log::info('Jubelio webhook stock (diabaikan, ERP source of truth)', $request->json()->all() ?: []);
        return response()->json(['ok' => true]);
    }
}
