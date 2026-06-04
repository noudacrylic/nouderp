<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MidtransWebhookController extends Controller
{
    public function __construct(protected MidtransService $midtrans)
    {
    }

    /**
     * POST /midtrans/notify  — di-protect VerifyMidtransSignature middleware.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all() ?: $request->all();

        try {
            $trx = $this->midtrans->handleNotification($payload);
            return response()->json([
                'ok' => true,
                'status' => $trx->status,
                'order_id' => $trx->order_id,
            ]);
        } catch (Throwable $e) {
            Log::error('Midtrans webhook handler error', [
                'order_id' => $payload['order_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Return 200 untuk yg sifatnya data error (mis. order_id tidak dikenal),
            // supaya Midtrans tidak retry terus. Tapi return 500 utk infra error supaya retry.
            $isDataError = str_contains($e->getMessage(), 'tidak ditemukan');
            return response()->json(['ok' => false, 'error' => $e->getMessage()], $isDataError ? 200 : 500);
        }
    }
}
