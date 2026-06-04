<?php

namespace App\Modules\Payment\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyMidtransSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->json()->all() ?: $request->all();

        $orderId = $payload['order_id'] ?? null;
        $statusCode = $payload['status_code'] ?? null;
        $grossAmount = $payload['gross_amount'] ?? null;
        $signature = $payload['signature_key'] ?? null;

        if (!$orderId || !$statusCode || !$grossAmount || !$signature) {
            Log::warning('Midtrans webhook missing field', $payload);
            return response()->json(['error' => 'invalid payload'], 400);
        }

        $serverKey = (string) config('services.midtrans.server_key');
        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if (!hash_equals($expected, $signature)) {
            Log::warning('Midtrans webhook bad signature', ['order_id' => $orderId]);
            return response()->json(['error' => 'invalid signature'], 403);
        }

        return $next($request);
    }
}
