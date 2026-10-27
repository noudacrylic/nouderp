<?php

namespace App\Modules\Production\Controllers;

use App\Core\Inventory\Product;
use App\Http\Controllers\Controller;
use App\Models\ProductionSetting;
use App\Modules\Production\Models\QcProfile;
use App\Modules\Production\Services\QcService;
use Illuminate\Http\Request;

/**
 * Sampel Acuan & Setting Toleransi QC Timbang — khusus admin (role_gate di
 * config/menu_permissions.php, entri production.qc-acuan). Petugas QC hanya menimbang.
 */
class QcAcuanController extends Controller
{
    public function __construct(private QcService $qc) {}

    public function index()
    {
        $profiles = QcProfile::with(['product:id,sku,name', 'components', 'sampler:id,name'])
            ->get()
            ->sortBy(fn ($p) => $p->product?->sku)
            ->values();

        $info = $profiles->mapWithKeys(fn ($p) => [$p->id => [
            'target'      => $this->qc->target($p),
            'toleransi'   => $this->qc->toleransiGram($p),
            'takTerdeteksi' => $this->qc->komponenTakTerdeteksi($p)->pluck('name')->all(),
        ]]);

        return view('erp.production.qc.acuan.index', compact('profiles', 'info'));
    }

    public function create()
    {
        return $this->form(new QcProfile(['is_active' => true]));
    }

    public function edit(int $id)
    {
        return $this->form(QcProfile::with(['product', 'components', 'history.user:id,name'])->findOrFail($id));
    }

    private function form(QcProfile $profil)
    {
        $salinan = QcProfile::with(['product:id,sku,name', 'components'])
            ->when($profil->exists, fn ($q) => $q->where('id', '!=', $profil->id))
            ->get()
            ->map(fn ($p) => [
                'id'        => $p->id,
                'label'     => ($p->product?->sku ?? '?') . ' — ' . ($p->product?->name ?? ''),
                'packaging' => $p->packaging_gram,
                // Varian beda ukuran = berat beda: yang disalin nama & jumlahnya saja.
                'components' => $p->components->map(fn ($k) => ['name' => $k->name, 'qty' => $k->qty])->values(),
            ])->values();

        return view('erp.production.qc.acuan.form', [
            'profil'    => $profil,
            'salinan'   => $salinan,
            'bawaan'    => $this->qc->toleransiBawaan(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validasi($request, true);
        $profil = $this->qc->simpanProfil($data, $request->user()?->id);

        return redirect()->route('production.qc-acuan.edit', $profil->id)->with('success', 'Profil QC tersimpan.');
    }

    public function update(Request $request, int $id)
    {
        $profil = QcProfile::findOrFail($id);
        $this->qc->simpanProfil($this->validasi($request, false), $request->user()?->id, $profil);

        return redirect()->route('production.qc-acuan.edit', $profil->id)->with('success', 'Profil QC diperbarui.');
    }

    /** Pencarian produk yang belum punya profil (JSON) untuk form profil baru. */
    public function produk(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        return response()->json(Product::query()
            ->whereNotIn('id', QcProfile::pluck('product_id'))
            ->where('is_active', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('sku', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('sku')
            ->limit(20)
            ->get(['id', 'sku', 'name']));
    }

    public function toleransi()
    {
        $profiles = QcProfile::with(['product:id,sku,name', 'components'])->where('is_active', true)->get()
            ->sortBy(fn ($p) => $p->product?->sku)->values();

        $info = $profiles->mapWithKeys(fn ($p) => [$p->id => [
            'target'        => $this->qc->target($p),
            'toleransi'     => $this->qc->toleransiGram($p),
            'takTerdeteksi' => $this->qc->komponenTakTerdeteksi($p),
        ]]);

        return view('erp.production.qc.acuan.toleransi', [
            'divisi'   => \App\Modules\Production\Models\Department::orderBy('name')->get(['id', 'name']),
            'divisiQc' => ProductionSetting::first()?->qc_department_id,
            'bawaan'   => $this->qc->toleransiBawaan(),
            'profiles' => $profiles,
            'info'     => $info,
        ]);
    }

    public function simpanToleransi(Request $request)
    {
        $data = $request->validate([
            'mode'  => 'required|in:gram,persen',
            'value' => 'required|numeric|min:0.1|max:1000',
            'qc_department_id' => 'nullable|integer|exists:production_departments,id',
        ]);

        $s = ProductionSetting::first() ?? new ProductionSetting();
        $s->forceFill([
            'qc_tolerance_mode'  => $data['mode'],
            'qc_tolerance_value' => $data['value'],
            'qc_department_id'   => $data['qc_department_id'] ?? null,
        ])->save();

        return back()->with('success', 'Setting QC disimpan.');
    }

    private function validasi(Request $request, bool $baru): array
    {
        return $request->validate([
            'product_id'            => $baru ? 'required|integer|exists:products,id|unique:qc_profiles,product_id' : 'nullable',
            'packaging_gram'        => 'nullable|numeric|min:0',
            'samples'               => 'nullable|array',
            'samples.*'             => 'nullable|numeric|min:0',
            'tolerance_mode'        => 'nullable|in:gram,persen',
            'tolerance_value'       => 'nullable|numeric|min:0.1',
            'batch_note'            => 'nullable|string|max:190',
            'is_active'             => 'nullable|boolean',
            'components'            => 'nullable|array',
            'components.*.name'     => 'nullable|string|max:100',
            'components.*.qty'      => 'nullable|integer|min:1',
            'components.*.unit_gram'=> 'nullable|numeric|min:0',
            'components.*.samples'  => 'nullable|array',
            'components.*.samples.*'=> 'nullable|numeric|min:0',
        ]) + ['product_id' => $request->input('product_id')];
    }
}
