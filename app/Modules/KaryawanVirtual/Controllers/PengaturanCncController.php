<?php

namespace App\Modules\KaryawanVirtual\Controllers;

use App\Core\Inventory\Product;
use App\Core\Inventory\Services\LajuJualService;
use App\Core\Inventory\Services\StokTersediaService;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Services\AntreanProduksiService;
use App\Modules\Production\Services\IsiAntreanCncService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan skill "Isi antrean CNC": kelompok mesin tiap produk (Mesin 1 double head /
 * Mesin 2 & 3), mesin mana yang double head, dan produk andalan (Tingkat 1).
 * Dibuka siapa pun yang boleh melihat karyawan virtualnya; menyimpan = super admin atau
 * yang punya akses menu Pengaturan Produksi (dulu produk andalan diatur di sana).
 */
class PengaturanCncController extends Controller
{
    private const SKILL = IsiAntreanCncService::SKILL;

    public function __construct(private KaryawanVirtualService $kv) {}

    public function show(Request $request, string $kode, AntreanProduksiService $antrean, LajuJualService $laju)
    {
        $cfg = $this->karyawan($kode);
        $cnc = $antrean->departemen('CNC');

        $mesin = $cnc
            ? DepartmentExecutor::where('department_id', $cnc->id)->whereNotNull('parent_executor_id')->orderBy('name')->get()
            : collect();

        $produk = $this->produkCnc($cnc?->id);
        $perHari = $laju->perHari($produk->pluck('id'));
        $tersedia = app(StokTersediaService::class)->untuk($produk->pluck('id'));

        return view('erp.karyawan-virtual.pengaturan-cnc', [
            'kode'     => $kode,
            'karyawan' => $cfg,
            'mesin'    => $mesin,
            'produk'   => $produk->map(fn (Product $p) => [
                'id'      => $p->id,
                'sku'     => $p->sku,
                'nama'    => $p->name,
                'ready'   => $p->sale_type === 'ready',
                'aktif'   => (bool) $p->is_active,
                'ganda'   => (bool) $p->cnc_kepala_ganda,
                'andalan' => (bool) $p->produk_andalan,
                'laku'    => round($perHari[$p->id] ?? 0, 1),
                'cukup'   => ($c = LajuJualService::hariCukup($tersedia[$p->id] ?? 0, $perHari[$p->id] ?? 0)) === null ? null : round($c, 1),
            ])->values(),
            'bolehUbah' => $this->bolehUbah($request),
        ]);
    }

    public function simpan(Request $request, string $kode)
    {
        $this->karyawan($kode);
        abort_unless($this->bolehUbah($request), 403, 'Hanya super admin atau yang punya akses Pengaturan Produksi yang boleh mengubah.');

        $data = $request->validate([
            'produk'         => 'nullable|array',
            'produk.*'       => 'integer|exists:products,id',
            'ganda'          => 'nullable|array',
            'ganda.*'        => 'integer',
            'andalan'        => 'nullable|array',
            'andalan.*'      => 'integer',
            'mesin'          => 'nullable|array',
            'mesin.*'        => 'integer|exists:production_department_executors,id',
            'kepala_ganda'   => 'nullable|array',
            'kepala_ganda.*' => 'integer',
        ]);

        // Hanya baris yang tampil di form yang disentuh — produk lain tak ikut ter-reset.
        $tampil  = collect($data['produk'] ?? [])->map(fn ($v) => (int) $v)->unique();
        $ganda   = collect($data['ganda'] ?? [])->map(fn ($v) => (int) $v)->intersect($tampil);
        $andalan = collect($data['andalan'] ?? [])->map(fn ($v) => (int) $v)->intersect($tampil);
        $mesin   = collect($data['mesin'] ?? [])->map(fn ($v) => (int) $v)->unique();
        $mGanda  = collect($data['kepala_ganda'] ?? [])->map(fn ($v) => (int) $v)->intersect($mesin);

        $bukanReady = Product::whereIn('id', $andalan)->where('sale_type', '!=', 'ready')->pluck('sku');
        if ($bukanReady->isNotEmpty()) {
            return back()->with('error', 'Produk andalan harus produk ready stok: ' . $bukanReady->implode(', ') . '.');
        }

        DB::transaction(function () use ($tampil, $ganda, $andalan, $mesin, $mGanda) {
            Product::whereIn('id', $tampil)->whereNotIn('id', $ganda)->update(['cnc_kepala_ganda' => false]);
            Product::whereIn('id', $ganda)->update(['cnc_kepala_ganda' => true]);
            Product::whereIn('id', $tampil)->whereNotIn('id', $andalan)->update(['produk_andalan' => false]);
            Product::whereIn('id', $andalan)->update(['produk_andalan' => true]);
            DepartmentExecutor::whereIn('id', $mesin)->whereNotIn('id', $mGanda)->update(['kepala_ganda' => false]);
            DepartmentExecutor::whereIn('id', $mGanda)->update(['kepala_ganda' => true]);
        });

        return back()->with('success', "Pengaturan disimpan: {$ganda->count()} produk Mesin 1, {$andalan->count()} produk andalan.");
    }

    /**
     * Produk utama BOM yang berlangkah CNC, ditambah produk andalan/Mesin 1 yang (sudah) tak
     * ber-BOM CNC supaya tandanya tetap bisa dicabut dari sini.
     */
    private function produkCnc(?int $cncId)
    {
        $ids = $cncId
            ? Bom::whereHas('steps', fn ($q) => $q->where('department_id', $cncId))
                ->with(['outputs' => fn ($q) => $q->where('output_type', 'main')])
                ->get()->flatMap(fn ($b) => $b->outputs->pluck('product_id'))
            : collect();

        return Product::where(fn ($q) => $q->whereIn('id', $ids->unique())
                ->orWhere('produk_andalan', true)->orWhere('cnc_kepala_ganda', true))
            ->orderBy('sku')
            ->get(['id', 'sku', 'name', 'sale_type', 'is_active', 'cnc_kepala_ganda', 'produk_andalan']);
    }

    private function bolehUbah(Request $request): bool
    {
        $u = $request->user();
        return $u && ($u->role === 'super_admin' || $u->hasMenuPermission('settings.production'));
    }

    private function karyawan(string $kode): array
    {
        $cfg = $this->kv->karyawan($kode);
        abort_if($cfg === null || !isset($cfg['skills'][self::SKILL]), 404);
        abort_unless($this->kv->bolehLihat($kode), 403, 'Anda tidak punya akses ke karyawan virtual ini.');
        return $cfg;
    }
}
