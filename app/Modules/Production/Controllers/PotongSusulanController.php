<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\KomponenLaporan;
use App\Modules\Production\Services\KomponenLaporanService;
use Illuminate\Http\Request;

/**
 * Potong susulan (28 Nov 2026): daftar komponen kurang/rusak yang dilaporkan Assembling. Saat
 * selesai dipotong, CNC memilih sumber bahan — stok (diarahkan ke Penambahan Bahan, terhubung
 * ke laporan ini) atau bahan sisa (tanpa penambahan bahan).
 * Di bawah production.process → akses mengikuti ?department_id (divisi CNC).
 */
class PotongSusulanController extends Controller
{
    public function index(Request $request, KomponenLaporanService $svc)
    {
        return view('erp.production.process.susulan', [
            'menunggu'     => $svc->menunggu(),
            'riwayat'      => KomponenLaporan::with(['items', 'productionOrder:id,order_number', 'pelapor:id,name'])
                ->where('status', '!=', 'menunggu')->latest('updated_at')->limit(30)->get(),
            'departmentId' => $request->query('department_id'),
        ]);
    }

    public function selesai(Request $request, int $id, KomponenLaporanService $svc)
    {
        $data = $request->validate(['sumber' => 'required|in:stok,sisa']);
        $lap = KomponenLaporan::with(['items', 'productionOrder:id,order_number', 'step:id,department_id'])->findOrFail($id);
        if ($lap->status !== 'menunggu') {
            return back()->with('error', 'Susulan ini sudah ditandai selesai.');
        }
        $svc->selesai($lap, $data['sumber'], auth()->id());

        if ($data['sumber'] === 'stok') {
            return redirect()->route('production.material-additions.create', [
                'department_id'       => $lap->step?->department_id,
                'step_id'             => $lap->production_order_step_id,
                'komponen_laporan_id' => $lap->id,
            ])->with('success', 'Susulan ditandai selesai. Catat bahan yang diambil dari stok di bawah ini.');
        }

        return redirect()->route('production.process.susulan', ['department_id' => $request->query('department_id')])
            ->with('success', "Susulan {$lap->productionOrder?->order_number} selesai (bahan sisa).");
    }
}
