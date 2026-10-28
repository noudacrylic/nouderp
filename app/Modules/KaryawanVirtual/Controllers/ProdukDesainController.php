<?php

namespace App\Modules\KaryawanVirtual\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Skills\DesainSkill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan skill Desain pesanan (Manajer Admin): daftar produk yang pesanannya selalu butuh
 * desain, ditulis sebagai SKU persis (TBKD-13) atau awalan berbintang (KS-* = semua Kotak Saran).
 * Produk baru yang butuh desain cukup ditambahkan di sini.
 */
class ProdukDesainController extends Controller
{
    public function __construct(private KaryawanVirtualService $kv, private MejaKerjaService $meja) {}

    public function index(Request $request, string $kode)
    {
        $karyawan = $this->admin($request, $kode);

        $aturan = collect($this->skill()->polaSku())->map(function ($pola) {
            $q = DB::table('products')->where('is_active', true)->where('sku', 'like', $pola);
            return [
                'pola'   => $pola,
                'tampil' => str_replace('%', '*', $pola),
                'jumlah' => (clone $q)->count(),
                'contoh' => $q->orderBy('sku')->limit(6)->get(['sku', 'name']),
            ];
        });

        return view('erp.karyawan-virtual.produk-desain', compact('aturan', 'kode', 'karyawan'));
    }

    public function tambah(Request $request, string $kode)
    {
        $this->admin($request, $kode);
        $isi = strtoupper(trim((string) $request->validate(['pola' => 'required|string|max:60'])['pola']));
        $pola = str_replace('*', '%', preg_replace('/\s+/', '', $isi));

        if (!DB::table('products')->where('is_active', true)->where('sku', 'like', $pola)->exists()) {
            return back()->withInput()->with('error', "Tidak ada produk aktif dengan SKU \"$isi\". Periksa penulisannya, atau pakai * untuk awalan (mis. KS-*).");
        }
        $semua = $this->skill()->polaSku();
        if (in_array($pola, $semua, true)) return back()->with('info', "\"$isi\" sudah ada di daftar.");

        $this->simpan([...$semua, $pola]);
        return back()->with('success', "\"$isi\" ditambahkan — pesanan berisi produk ini sekarang dibuatkan task desain.");
    }

    public function hapus(Request $request, string $kode)
    {
        $this->admin($request, $kode);
        $pola = (string) $request->input('pola');
        $this->simpan(array_values(array_diff($this->skill()->polaSku(), [$pola])));

        return back()->with('success', '"' . str_replace('%', '*', $pola) . '" dihapus dari daftar.');
    }

    private function skill(): DesainSkill
    {
        return $this->meja->skill('desain');
    }

    private function simpan(array $pola): void
    {
        $atur = $this->meja->pengaturan($this->skill());
        $atur->update(['opsi' => array_merge($atur->opsi ?? [], ['pola_sku' => array_values($pola)])]);
    }

    private function admin(Request $request, string $kode): array
    {
        abort_unless($kode === MejaKerjaService::KARYAWAN, 404);
        abort_unless(in_array($request->user()?->role, ['super_admin', 'admin'], true), 403, 'Hanya admin yang boleh mengubah daftar produk desain.');
        return $this->kv->karyawan($kode);
    }
}
