<?php

namespace App\Modules\KaryawanVirtual\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Purchasing\Services\StokBahanService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan skill Purchasing ready stok (Manajer Admin): saran min stok bahan beli = pemakaian per
 * hari × (lama datang + hari cadangan). Admin meninjau lalu menyimpan per bahan — tidak pernah
 * ditulis otomatis.
 */
class MinStokController extends Controller
{
    public function __construct(private StokBahanService $stok, private KaryawanVirtualService $kv) {}

    public function index(Request $request, string $kode)
    {
        $karyawan = $this->admin($request, $kode);
        $hari     = max(14, min(365, (int) $request->input('hari', 90)));
        $cadangan = max(0, min(60, (int) $request->input('cadangan', 7)));
        $rows     = $this->stok->saranMinStok($hari, $cadangan);

        return view('erp.karyawan-virtual.min-stok', compact('rows', 'hari', 'cadangan', 'kode', 'karyawan'));
    }

    public function simpan(Request $request, string $kode)
    {
        $this->admin($request, $kode);
        $data = $request->validate(['min' => 'array', 'min.*' => 'nullable|string|max:30']);
        $boleh = $this->stok->bahanBeliIds()->flip();

        $ubah = 0;
        DB::transaction(function () use ($data, $boleh, &$ubah) {
            foreach ($data['min'] ?? [] as $id => $isi) {
                if (!isset($boleh[(int) $id])) continue;
                $baru = trim((string) $isi) === '' ? null : max(0, (float) clean_number($isi));
                $baru = $baru ?: null;
                $lama = DB::table('products')->where('id', $id)->value('min_stock');
                if (($lama === null ? null : (float) $lama) === $baru) continue;
                DB::table('products')->where('id', $id)->update(['min_stock' => $baru, 'updated_at' => now()]);
                $ubah++;
            }
        });

        return back()->with('success', "Min stok $ubah bahan diperbarui.");
    }

    private function admin(Request $request, string $kode): array
    {
        abort_unless($kode === MejaKerjaService::KARYAWAN, 404);
        abort_unless(in_array($request->user()?->role, ['super_admin', 'admin'], true), 403, 'Hanya admin yang boleh mengubah min stok.');
        return $this->kv->karyawan($kode);
    }
}
