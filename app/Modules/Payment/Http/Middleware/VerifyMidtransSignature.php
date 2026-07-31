<?php

namespace App\Modules\Payment\Http\Middleware;

use App\Models\MidtransSetting;
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

        // Kunci diambil dari Pengaturan → Midtrans, sumber yang SAMA dengan yang dipakai
        // MidtransService saat membuat transaksi. Dulu di sini dibaca dari .env, sehingga
        // mengganti kunci lewat UI (mis. saat pindah ke produksi) membuat pembuatan
        // transaksi memakai kunci baru sementara verifikasi webhook masih kunci lama —
        // semua notifikasi ditolak 403 tanpa gejala yang terlihat di layar.
        $serverKey = MidtransSetting::resolvedServerKey();

        if ($serverKey === '') {
            Log::error('Midtrans webhook ditolak: server key belum dikonfigurasi');
            return response()->json(['error' => 'server key not configured'], 500);
        }

        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if (!hash_equals($expected, $signature)) {
            Log::warning('Midtrans webhook bad signature', ['order_id' => $orderId]);
            return response()->json(['error' => 'invalid signature'], 403);
        }

        return $next($request);
    }
}
