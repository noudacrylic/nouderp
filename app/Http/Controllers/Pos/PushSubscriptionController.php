<?php

namespace App\Http\Controllers\Pos;

use App\Models\PushSubscription;
use App\Modules\Notifications\Services\WebPushNotifier;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Endpoint langganan Web Push untuk tim packing di aplikasi ERP desktop (dipanggil
 * dari JS via fetch). Berbagi tabel push_subscriptions dengan PWA karyawan; pembeda
 * penerima notifikasi pesanan = role user (bukan 'karyawan'), lihat WebPushNotifier.
 */
class PushSubscriptionController extends Controller
{
    /** Simpan/segarkan langganan perangkat saat user mengaktifkan notifikasi pesanan. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'endpoint'        => 'required|string|max:500',
            'keys.p256dh'     => 'required|string|max:255',
            'keys.auth'       => 'required|string|max:255',
            'contentEncoding' => 'nullable|string|max:20',
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id'          => $request->user()->id,
                'public_key'       => $data['keys']['p256dh'],
                'auth_token'       => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'user_agent'       => substr((string) $request->userAgent(), 0, 255),
            ]
        );

        return response()->json(['ok' => true]);
    }

    /** Hapus langganan perangkat saat user menonaktifkan notifikasi. */
    public function destroy(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string|max:500']);

        PushSubscription::where('endpoint', $data['endpoint'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** Kirim notifikasi uji ke perangkat user (konfirmasi setelah aktivasi). */
    public function test(Request $request, WebPushNotifier $push)
    {
        $sent = $push->notifyUser(
            $request->user(),
            '🔔 Notifikasi pesanan aktif',
            'Anda akan diberi tahu di sini saat ada pesanan instant masuk.',
            ['url' => route('pos.fulfillment.perlu-diproses'), 'tag' => 'erp-push-test']
        );

        return response()->json(['ok' => true, 'sent' => $sent]);
    }
}
