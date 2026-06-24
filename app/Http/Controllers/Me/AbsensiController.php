<?php

namespace App\Http\Controllers\Me;

use App\Modules\SDM\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class AbsensiController extends Controller
{
    public function index(Request $request)
    {
        $karyawan = auth()->user()->karyawan;

        // Navigasi bulan: ?bulan=&tahun= (default bulan berjalan).
        $now   = Carbon::now();
        $tahun = (int) $request->input('tahun', $now->year);
        $bulan = (int) $request->input('bulan', $now->month);
        if ($bulan < 1 || $bulan > 12) $bulan = $now->month;

        $cursor = Carbon::create($tahun, $bulan, 1);

        $rows = Attendance::where('karyawan_id', $karyawan->id)
            ->whereYear('tanggal', $cursor->year)
            ->whereMonth('tanggal', $cursor->month)
            ->orderBy('tanggal', 'desc')
            ->get();

        // Tidak boleh menengok masa depan.
        $prev = $cursor->copy()->subMonth();
        $next = $cursor->copy()->addMonth();
        $canNext = $next->startOfMonth()->lte($now->copy()->startOfMonth());

        return view('me.absensi', [
            'karyawan' => $karyawan,
            'rows'     => $rows,
            'cursor'   => $cursor,
            'prev'     => ['bulan' => $prev->month, 'tahun' => $prev->year],
            'next'     => $canNext ? ['bulan' => $next->month, 'tahun' => $next->year] : null,
        ]);
    }
}
