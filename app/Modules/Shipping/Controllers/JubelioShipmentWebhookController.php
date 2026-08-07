<?php

namespace App\Modules\Shipping\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Shipping\Providers\JubelioShipmentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook status pengiriman Jubelio Shipment (kontrak v1.8 bab 7).
 *
 * URL didaftarkan di Dashboard Jubelio Shipment → Setting → Developer → Webhook,
 * beserta secret-nya. Tidak ber-auth (server-to-server); yang menjaga adalah
 * tanda tangan `x-jubelio-signature`.
 */
class JubelioShipmentWebhookController extends Controller
{
    public function __construct(protected JubelioShipmentProvider $provider)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        // Verifikasi memakai BODY MENTAH, bukan hasil decode — array PHP yang di-encode
        // ulang bisa berbeda urutan/spasi sehingga tanda tangannya tidak akan cocok.
        $raw = $request->getContent();

        if (!$this->provider->verifyWebhook($raw, $request->header('x-jubelio-signature'))) {
            Log::warning('Webhook Jubelio Shipment ditolak: tanda tangan tidak cocok', [
                'ref_no' => $request->input('ref_no'),
                'awb'    => $request->input('awb'),
            ]);

            return response()->json(['error' => 'invalid signature'], 403);
        }

        $awb    = (string) $request->input('awb', '');
        $status = (string) $request->input('latest_status', '');

        if ($awb === '') {
            return response()->json(['ok' => false, 'error' => 'awb kosong'], 200);
        }

        $delivery = SalesDelivery::where('tracking_number', $awb)
            ->orWhere('provider_order_id', (string) $request->input('shipment_id'))
            ->first();

        if (!$delivery) {
            // Bukan error kita — bisa jadi resi dibuat di dashboard Jubelio, bukan dari ERP.
            // Dijawab 200 supaya Jubelio tidak mengulang-ulang kiriman yang sama.
            Log::info('Webhook Jubelio Shipment: resi tidak dikenal', ['awb' => $awb, 'status' => $status]);

            return response()->json(['ok' => false, 'error' => 'resi tidak dikenal'], 200);
        }

        $internal = JubelioShipmentProvider::internalStatus($status);

        $delivery->update(array_filter([
            'shipping_status' => $internal,
            'shipping_raw'    => $request->all(),
        ], fn ($v) => $v !== null));

        // DELIVERED = paket diterima pembeli → tandai sampai supaya pesanan pindah sendiri dari
        // tab "Dikirim" ke "Selesai" tanpa ada yang harus menekan tombol. Idempoten: Jubelio
        // boleh mengirim status yang sama berkali-kali.
        $autoSelesai = $internal === 'delivered' && $delivery->markDelivered();

        return response()->json([
            'ok'              => true,
            'delivery_number' => $delivery->delivery_number,
            'status'          => $internal ?? $status,
            'delivered'       => $delivery->delivered_at !== null,
            'auto_selesai'    => $autoSelesai,
        ]);
    }
}
