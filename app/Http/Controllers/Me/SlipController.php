<?php

namespace App\Http\Controllers\Me;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\SlipGaji;
use Illuminate\Routing\Controller;

/**
 * PWA Karyawan — slip gaji. HANYA periode finalized. Layar HP menampilkan
 * take-home (diterima bersih) + tombol lihat/cetak slip resmi (reuse view cetak).
 */
class SlipController extends Controller
{
    public function index()
    {
        $karyawan = auth()->user()->karyawan;

        $slips = SlipGaji::with('periode')
            ->where('karyawan_id', $karyawan->id)
            ->where('status', 'finalized')
            ->get()
            ->sortByDesc(fn ($s) => sprintf('%04d%02d', $s->periode->tahun ?? 0, $s->periode->bulan ?? 0))
            ->values();

        return view('me.slip.index', compact('karyawan', 'slips'));
    }

    /** Lihat / cetak slip resmi (same-tab) — reuse partial cetak ERP. */
    public function show(int $id)
    {
        $karyawan = auth()->user()->karyawan;

        $slip = SlipGaji::with(['karyawan.department', 'periode', 'komponen'])
            ->where('karyawan_id', $karyawan->id)
            ->where('status', 'finalized')
            ->findOrFail($id);

        $attendances = Attendance::where('periode_id', $slip->periode_id)
            ->where('karyawan_id', $slip->karyawan_id)
            ->orderBy('tanggal')
            ->get();

        return view('me.slip.print', compact('slip', 'attendances'));
    }
}
