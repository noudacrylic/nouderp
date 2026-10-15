<?php

namespace App\Http\Controllers;

use App\Models\IntercomCall;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Intercom divisi: layar stasiun + pencatat log panggilan.
 *
 * Sinyal panggilannya tidak lewat controller ini — ia dikirim antar peramban
 * sebagai client event Reverb di saluran privat `intercom` (routes/channels.php).
 */
class IntercomController extends Controller
{
    /** Layar stasiun: tombol besar per divisi + riwayat panggilan hari ini. */
    public function index()
    {
        abort_unless(config('intercom.enabled'), 404);

        $riwayat = IntercomCall::with('user:id,name')
            ->where('mulai_at', '>=', now()->subDays(2)->startOfDay())
            ->latest('mulai_at')
            ->limit(30)
            ->get();

        return view('erp.intercom.index', [
            'stasiun' => config('intercom.stasiun'),
            'riwayat' => $riwayat,
        ]);
    }

    /** Dicatat oleh peramban PEMANGGIL saat panggilan berakhir. */
    public function catat(Request $request)
    {
        $kunci = array_keys(config('intercom.stasiun'));

        $data = $request->validate([
            'dari'         => ['required', Rule::in($kunci)],
            'ke'           => ['required', Rule::in($kunci), 'different:dari'],
            'status'       => ['required', Rule::in(IntercomCall::STATUS)],
            'durasi_detik' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $durasi = (int) ($data['durasi_detik'] ?? 0);

        IntercomCall::create([
            'dari'         => $data['dari'],
            'ke'           => $data['ke'],
            'user_id'      => $request->user()->id,
            'status'       => $data['status'],
            'durasi_detik' => $durasi,
            'mulai_at'     => now()->subSeconds($durasi),
        ]);

        return response()->json(['ok' => true]);
    }
}
