<?php

namespace App\Http\Controllers\Me;

use App\Models\PushSubscription;
use App\Modules\Notifications\Services\WebPushNotifier;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Endpoint langganan Web Push untuk PWA Karyawan (dipanggil dari JS via fetch).
 */
class PushSubscriptionController extends Controller
{
    /** Simpan/segarkan langganan perangkat saat karyawan mengaktifkan notifikasi. */
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

    /** Hapus langganan perangkat saat karyawan menonaktifkan notifikasi. */
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
            '🔔 Notifikasi aktif',
            'Anda akan menerima pemberitahuan penting di sini.',
            ['url' => route('me.home'), 'tag' => 'test']
        );

        return response()->json(['ok' => true, 'sent' => $sent]);
    }
}
