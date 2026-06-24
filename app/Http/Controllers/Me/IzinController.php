<?php

namespace App\Http\Controllers\Me;

use App\Modules\Notifications\Services\TelegramNotifier;
use App\Modules\SDM\Models\IzinRequest;
use App\Modules\SDM\Services\IzinRequestService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * PWA Karyawan — pengajuan izin (lihat daftar + ajukan baru).
 * Approval ada di sisi admin (web): modul Absensi → tab Pengajuan Izin.
 */
class IzinController extends Controller
{
    public function __construct(private IzinRequestService $service) {}

    public function index()
    {
        $karyawan = auth()->user()->karyawan;

        $requests = IzinRequest::where('karyawan_id', $karyawan->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('me.izin.index', compact('karyawan', 'requests'));
    }

    public function create()
    {
        $types = IzinRequest::TYPES;
        return view('me.izin.create', compact('types'));
    }

    public function store(Request $request, TelegramNotifier $telegram)
    {
        $karyawan = auth()->user()->karyawan;

        $data = $request->validate([
            'type'                => 'required|in:' . implode(',', array_keys(IzinRequest::TYPES)),
            'tanggal'             => 'required|date',
            'tanggal_akhir'       => 'nullable|date',
            'paired_date'         => 'nullable|date',
            'sesi'                => 'nullable|in:pagi,sore',
            'paired_sesi'         => 'nullable|in:pagi,sore',
            'jam_masuk_override'  => 'nullable|date_format:H:i',
            'jam_pulang_override' => 'nullable|date_format:H:i',
            'alasan'              => 'required|string|max:1000',
            'lampiran'            => 'nullable|image|max:5120',
        ]);

        try {
            $izin = $this->service->submit($karyawan, $data, $request->file('lampiran'));
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Notifikasi approver via Telegram (no-op aman bila bot belum dikonfigurasi).
        $tgl = $izin->tanggal->translatedFormat('d M Y')
            . ($izin->tanggal_akhir ? ' s/d ' . $izin->tanggal_akhir->translatedFormat('d M Y') : '');
        $reviewUrl = route('sdm.pengajuan-izin.index', [
            'status'    => 'pending',
            'highlight' => $izin->id,
        ]) . '#izin-' . $izin->id;
        $telegram->notifyApprovers(
            "🔔 <b>Pengajuan izin baru</b>\n"
            . "Karyawan: {$karyawan->name}\n"
            . "Tipe: {$izin->typeLabel()}\n"
            . "Tanggal: {$tgl}\n"
            . "Alasan: {$izin->alasan}\n\n"
            . "👉 <a href=\"{$reviewUrl}\">Buka halaman Pemrosesan Izin</a>"
        );

        return redirect()->route('me.izin')->with('success', 'Pengajuan izin terkirim. Menunggu persetujuan.');
    }
}
