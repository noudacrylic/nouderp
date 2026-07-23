<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentSetting;
use App\Modules\Sales\Services\Payment\QrislyProvider;
use App\Modules\Sales\Services\WebPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook QRISLY: dipanggil Komerce saat status pembayaran berubah.
 *
 * Dilindungi rahasia di URL (pola sama dengan webhook Telegram) karena Komerce
 * tidak menyediakan tanda tangan payload. Status TIDAK dipercaya mentah-mentah —
 * selalu diverifikasi ulang ke endpoint payment-status sebelum uang diakui.
 */
class QrislyWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, WebPaymentService $payments)
    {
        $setting = PaymentSetting::singleton();
        $expected = $setting->qrisWebhookSecret();

        if (! $expected || ! hash_equals($expected, $secret)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $payload   = $request->all();
        $historyId = (string) (data_get($payload, 'history_id')
            ?? data_get($payload, 'data.history_id')
            ?? data_get($payload, 'id')
            ?? '');

        if ($historyId === '') {
            Log::warning('QRISLY webhook tanpa history_id', ['payload' => $payload]);
            return response()->json(['message' => 'history_id tidak ada'], 422);
        }

        // Verifikasi ke sumber: webhook hanya pemicu, bukan bukti.
        try {
            $status = (new QrislyProvider($setting))->status($historyId);
        } catch (\Throwable $e) {
            Log::error('QRISLY webhook: gagal verifikasi status', ['history_id' => $historyId, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Verifikasi gagal'], 202); // biar Komerce kirim ulang
        }

        if (! $status['paid']) {
            return response()->json(['message' => 'Belum lunas', 'status' => $status['status']]);
        }

        $wp = $payments->confirmFromQris($historyId, $status['amount'], 'qris');

        return response()->json([
            'message' => $wp ? 'OK' : 'Intent tidak dikenal',
            'status'  => $wp?->status,
        ]);
    }
}
