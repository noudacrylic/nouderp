<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Services\KoreksiTimerService;
use App\Modules\Production\Services\PenilaianKinerjaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Koreksi timer langkah produksi dari halaman OP — khusus owner, karena timer adalah dasar
 * penilaian & bonus. Dipakai saat karyawan lapor lupa Pending (lembur bongkar barang, dll).
 */
class KoreksiTimerController extends Controller
{
    public function __construct(private KoreksiTimerService $koreksi) {}

    private function khususOwner(Request $request): void
    {
        abort_unless($request->user()?->role === 'super_admin', 403, 'Koreksi timer hanya untuk owner.');
    }

    public function keluarkan(Request $request, int $stepId)
    {
        $this->khususOwner($request);
        $data = $request->validate([
            'dari'       => 'required|date',
            'sampai'     => 'required|date',
            'alasan'     => 'required|string|max:40',
            'keterangan' => 'nullable|string|max:200',
        ]);

        try {
            $detik = $this->koreksi->keluarkan($stepId, Carbon::parse($data['dari']), Carbon::parse($data['sampai']),
                $data['alasan'], $data['keterangan'] ?? null, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('success', PenilaianKinerjaService::teksMenit($detik / 60) . ' dikeluarkan dari timer.');
    }

    public function batalkan(Request $request, int $stepId, string $grup)
    {
        $this->khususOwner($request);
        $n = $this->koreksi->batalkan($stepId, $grup);

        return back()->with($n ? 'success' : 'error', $n ? 'Koreksi timer dibatalkan.' : 'Koreksi tidak ditemukan.');
    }
}
