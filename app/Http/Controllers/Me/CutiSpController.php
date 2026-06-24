<?php

namespace App\Http\Controllers\Me;

use App\Modules\SDM\Models\Attendance;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * PWA Karyawan — layar "Cuti & SP" (read-only).
 *
 * Cuti: hak tahunan (sdm_karyawan.hak_cuti, default 12) vs terpakai = jumlah
 *       baris absensi berstatus 'cuti' tahun berjalan (sumber tetap fingerprint
 *       /override — tidak memfabrikasi, lih. project_absensi_log_only_2026_06_17).
 * SP:   daftar Surat Peringatan aktif (scope berlaku) + riwayat tidak aktif.
 */
class CutiSpController extends Controller
{
    public function index()
    {
        $karyawan = auth()->user()->karyawan;
        $year     = Carbon::now()->year;

        // ── Cuti ───────────────────────────────────────────────
        $hakCuti = (int) ($karyawan->hak_cuti ?? 12);

        $cutiRows = Attendance::where('karyawan_id', $karyawan->id)
            ->where('status', 'cuti')
            ->whereYear('tanggal', $year)
            ->orderBy('tanggal', 'desc')
            ->get();

        $cutiTerpakai = $cutiRows->count();
        $sisaCuti     = max(0, $hakCuti - $cutiTerpakai);

        // ── SP ─────────────────────────────────────────────────
        $spAktif   = $karyawan->spHistory()->berlaku()->get();
        $spRiwayat = $karyawan->spHistory()
            ->whereNotIn('id', $spAktif->pluck('id'))
            ->get();

        return view('me.cuti-sp', compact(
            'karyawan', 'year', 'hakCuti', 'cutiRows', 'cutiTerpakai', 'sisaCuti', 'spAktif', 'spRiwayat'
        ));
    }
}
