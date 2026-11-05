<?php

namespace App\Modules\Production\Controllers;

use App\Core\Inventory\Product;
use App\Http\Controllers\Controller;
use App\Models\ProductionSetting;
use App\Modules\Production\Models\QcProfile;
use App\Modules\Production\Services\QcService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sampel Acuan QC Timbang (izin menu production.qc-acuan) & Setting Toleransi
 * (production.qc-toleransi), keduanya dipegang Admin Layanan. Tanpa izin toleransi, toleransi per
 * profil juga tidak bisa diubah.
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

    /** ?produk=ID: tambah sampel untuk produk yang sudah ber-profil. */
    public function create(Request $request)
    {
        return $this->form(new QcProfile(['is_active' => true, 'product_id' => $request->integer('produk') ?: null]));
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
                'label'     => ($p->product?->sku ?? '?') . ' — ' . ($p->product?->name ?? '') . ' (' . $p->label . ')',
                'packaging' => $p->packaging_gram,
                // Varian beda ukuran = berat beda: yang disalin nama & jumlahnya saja.
                'components' => $p->components->map(fn ($k) => ['name' => $k->name, 'qty' => $k->qty])->values(),
            ])->values();

        // Sampel baru produk yang sudah ber-profil: komponennya sama, hanya beratnya yang ditimbang ulang.
        $bibit = !$profil->exists && $profil->product_id
            ? QcProfile::with('components')->where('product_id', $profil->product_id)->orderBy('id')->first()
            : null;

        return view('erp.production.qc.acuan.form', [
            'profil'    => $profil,
            'bibit'     => $bibit,
            'salinan'   => $salinan,
            'bawaan'    => $this->qc->toleransiBawaan(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->saringToleransi($this->validasi($request));
        $profil = $this->qc->simpanProfil($data, $request->user()?->id);

        return redirect()->route('production.qc-acuan.edit', $profil->id)->with('success', 'Profil QC tersimpan.');
    }

    public function update(Request $request, int $id)
    {
        $profil = QcProfile::findOrFail($id);
        $this->qc->simpanProfil($this->saringToleransi($this->validasi($request, $profil), $profil), $request->user()?->id, $profil);

        return redirect()->route('production.qc-acuan.edit', $profil->id)->with('success', 'Profil QC diperbarui.');
    }

    /** Pencarian produk yang belum punya profil (JSON). Sampel tambahan lewat tombol + Sampel. */
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
        ]);

        $s = ProductionSetting::first() ?? new ProductionSetting();
        $s->forceFill([
            'qc_tolerance_mode'  => $data['mode'],
            'qc_tolerance_value' => $data['value'],
        ])->save();

        return back()->with('success', 'Setting QC disimpan.');
    }

    /** Tanpa hak toleransi: nilainya tetap yang sudah tersimpan, profil baru ikut bawaan. */
    private function saringToleransi(array $data, ?QcProfile $profil = null): array
    {
        if (user_can_access('production.qc-toleransi')) {
            return $data;
        }

        return array_merge($data, [
            'tolerance_mode'  => $profil?->tolerance_mode,
            'tolerance_value' => $profil?->tolerance_value,
        ]);
    }

    private function validasi(Request $request, ?QcProfile $profil = null): array
    {
        $productId = $profil?->product_id ?? (int) $request->input('product_id');

        return $request->validate([
            'product_id'            => $profil ? 'nullable' : 'required|integer|exists:products,id',
            'label'                 => ['nullable', 'string', 'max:60',
                Rule::unique('qc_profiles', 'label')->where('product_id', $productId)->ignore($profil?->id)],
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
        ], ['label.unique' => 'Nama sampel ini sudah dipakai produk yang sama.']) + ['product_id' => $productId];
    }
}
