<?php

namespace App\Modules\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\BomScoreService;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Models\ProductionSetting;
use App\Modules\Production\Models\ProductionByproduct;
use App\Modules\Production\Models\ProductionRawMaterial;
use App\Core\Inventory\Product;

class BomController extends Controller
{
    public function index(Request $request, BomScoreService $scoreService)
    {
        $scoreService->recalculateAll();

        $q = trim((string) $request->get('q', ''));

        $boms = Bom::withCount(['materials', 'outputs', 'steps'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('bom_number', 'LIKE', "%{$q}%")
                      ->orWhere('name', 'LIKE', "%{$q}%");
                });
            })
            ->orderByDesc('score')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('erp.production.boms.index', compact('boms'));
    }

    public function toggleAuto(int $id, BomService $service)
    {
        $bom = Bom::findOrFail($id);
        try {
            $service->setAutoProduction($id, !$bom->auto_production);
            $msg = $bom->fresh()->auto_production
                ? "Auto-produksi BOM {$bom->bom_number} diaktifkan."
                : "Auto-produksi BOM {$bom->bom_number} dinonaktifkan.";
            return back()->with('success', $msg);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function updateCycles(Request $request, int $id)
    {
        $data = $request->validate([
            'typical_cycles' => 'required|integer|min:1',
        ]);

        $bom = Bom::findOrFail($id);
        $bom->update(['typical_cycles' => $data['typical_cycles']]);

        return back()->with('success', "Jumlah siklus BOM {$bom->bom_number} diperbarui menjadi {$data['typical_cycles']}.");
    }

    /**
     * Tombol "Jalankan Auto Produksi" menjalankan DUA mesin yang saling melengkapi, karena
     * masing-masing buta terhadap separuh kebutuhan:
     *
     * 1. AutoProductionService  — produk READY, dipicu stok menipis/habis. Mesin ini sengaja
     *    melewati semua produk pre-order (lihat AutoProductionService::runForBom).
     * 2. PreorderAutoProductionService — produk PRE-ORDER, dipicu pesanan. Normalnya berjalan
     *    sendiri saat DP di-post, tapi penilaiannya sekali jalan: kalau saat itu stok kebetulan
     *    menutupi, OP tidak dibuat dan tidak ada yang mengevaluasi ulang bila stok itu kemudian
     *    hilang (opname minus, rusak, terpakai pesanan lain). Sapuan di sini yang menutup celah
     *    tersebut — lihat sweepPendingSalesOrders().
     *
     * Tanpa nomor 2, tombol ini tidak akan pernah bisa membuat OP untuk pesanan pre-order yang
     * menggantung, dan satu-satunya jalan adalah bikin OP manual.
     */
    public function runAuto(KaryawanVirtualService $kv)
    {
        // Dikerjakan Karyawan Virtual "Manager Produksi": mode skill berlaku juga untuk tombol
        // ini (Bayangan → hanya dicatat), dan hasilnya masuk jurnal kerjanya.
        $ket = 'Tombol "Jalankan Auto Produksi" (halaman BOM)';
        $stok            = $kv->jalankan('produksi.op-stok-menipis', $kv::PEMICU_MANUAL, $ket, auth()->id());
        $stockResults    = $stok['hasil'];
        $preorderResults = $kv->jalankan('produksi.op-preorder', $kv::PEMICU_MANUAL, $ket, auth()->id())['hasil'];
        $catatanMode     = match ($stok['mode']) {
            'nonaktif' => ' Skill "Buat OP saat stok menipis" sedang NONAKTIF — BOM stok tidak diperiksa.',
            'otomatis' => '',
            'persetujuan' => ' Skill "Buat OP saat stok menipis" mode MINTA PERSETUJUAN: '
                . count($stok['usulan']) . ' OP stok diusulkan / menunggu persetujuan (Telegram atau halaman Karyawan Virtual).',
            default    => ' Skill "Buat OP saat stok menipis" mode ' . strtoupper($stok['mode'])
                . ': ' . collect($stockResults)->where('simulasi', true)->count() . ' OP stok hanya dicatat di jurnal, tidak dibuat.',
        };

        $createdStock    = collect($stockResults)->where('created', true);
        $createdPreorder = collect($preorderResults)->where('created', true);
        $createdCount    = $createdStock->count() + $createdPreorder->count();

        if ($stockResults === [] && $preorderResults === []) {
            return back()->with('info', 'Belum ada BOM dengan auto-produksi aktif, dan tidak ada pesanan pre-order yang menunggu produksi.' . $catatanMode);
        }

        $bomDiperiksa = count($stockResults);
        $soDiperiksa  = collect($preorderResults)->pluck('sales_order_id')->unique()->count();

        if ($createdCount === 0) {
            // Alasan skip ditampilkan dari kedua sisi supaya tidak menyesatkan: kalau hanya
            // alasan BOM yang muncul, pesanan pre-order yang di-skip terlihat seperti tidak
            // pernah diperiksa sama sekali.
            $alasan = collect($stockResults)->where('created', false)->take(2)->pluck('reason')
                ->merge(collect($preorderResults)->where('created', false)->take(2)->pluck('reason'))
                ->filter()->join(' · ');

            return back()->with('info', "Tidak ada order baru. Diperiksa {$bomDiperiksa} BOM stok + {$soDiperiksa} pesanan pre-order. " . $alasan . $catatanMode);
        }

        $list = $createdStock->map(fn($r) => "{$r['order_number']} ({$r['bom_number']})")
            ->merge($createdPreorder->map(fn($r) => "{$r['order_number']} (pre-order {$r['sales_order_number']})"))
            ->join(', ');

        return back()->with('success', "{$createdCount} order otomatis dibuat: {$list}. Diperiksa {$bomDiperiksa} BOM stok + {$soDiperiksa} pesanan pre-order." . $catatanMode);
    }

    public function create()
    {
        $departments = Department::where('type', 'produksi')->where('is_active', true)->orderBy('name')->get();
        $byproductOptions = $this->byproductOptions();
        return view('erp.production.boms.create', compact('departments', 'byproductOptions'));
    }

    /** Opsi produk sampingan untuk dropdown di form BOM/OP. */
    private function byproductOptions(): array
    {
        return ProductionByproduct::with('product')->get()
            // Produk sampingan yang produknya diarsipkan tidak boleh jadi opsi baru.
            ->filter(fn($b) => $b->product && $b->product->is_active)
            ->map(fn($b) => [
                'id'              => $b->product_id,
                'label'           => ($b->product?->sku ? $b->product->sku . ' - ' : '') . ($b->product?->name ?? '—'),
                'unit_percentage' => (float) $b->percentage,
            ])->values()->all();
    }

    public function store(Request $request, BomService $service)
    {
        $request->validate([
            'name'                      => 'required|string|max:200',
            'typical_cycles'            => 'nullable|integer|min:1',
            'auto_production'           => 'nullable|boolean',
            'materials'                 => 'required|array|min:1',
            'materials.*.product_id'    => 'required|exists:products,id',
            'materials.*.qty_per_cycle' => 'required|numeric|min:0.0001',
            'outputs'                   => 'required|array|min:1',
            'outputs.*.product_id'      => 'required|exists:products,id',
            'outputs.*.qty_per_cycle'   => 'required|numeric|min:0.0001',
            'outputs.*.output_type'     => 'required|in:main,by_product',
            'outputs.*.percentage'      => 'required|numeric|min:0|max:100',
            'outputs.*.unit_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $bom = $service->create($request->all());
            return redirect(list_url('production.boms.index'))
                ->with('success', "BOM {$bom->bom_number} berhasil dibuat.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(int $id)
    {
        $bom = Bom::with(['materials.product', 'outputs.product', 'steps.department'])->findOrFail($id);
        $departments = Department::where('type', 'produksi')->where('is_active', true)->orderBy('name')->get();
        $byproductOptions = $this->byproductOptions();
        return view('erp.production.boms.create', compact('bom', 'departments', 'byproductOptions'));
    }

    public function update(Request $request, int $id, BomService $service)
    {
        $request->validate([
            'name'                      => 'required|string|max:200',
            'typical_cycles'            => 'nullable|integer|min:1',
            'auto_production'           => 'nullable|boolean',
            'materials'                 => 'required|array|min:1',
            'materials.*.product_id'    => 'required|exists:products,id',
            'materials.*.qty_per_cycle' => 'required|numeric|min:0.0001',
            'outputs'                   => 'required|array|min:1',
            'outputs.*.product_id'      => 'required|exists:products,id',
            'outputs.*.qty_per_cycle'   => 'required|numeric|min:0.0001',
            'outputs.*.output_type'     => 'required|in:main,by_product',
            'outputs.*.percentage'      => 'required|numeric|min:0|max:100',
            'outputs.*.unit_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $service->update($id, $request->all());
            return redirect(list_url('production.boms.index'))
                ->with('success', 'BOM berhasil diperbarui.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(int $id, BomService $service)
    {
        try {
            $service->delete($id);
            return back()->with('success', 'BOM berhasil dihapus.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function clone(int $id, BomService $service)
    {
        try {
            $newBom = $service->clone($id);
            return redirect()->route('production.boms.edit', $newBom->id)
                ->with('success', "BOM disalin sebagai {$newBom->bom_number}.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function calculate(Request $request, BomService $service)
    {
        $request->validate([
            'bom_id' => 'required|exists:boms,id',
            'cycles' => 'required|numeric|min:0.0001',
        ]);

        $bom = \App\Modules\Production\Models\Bom::findOrFail($request->bom_id);

        return response()->json([
            'materials'   => $service->calculateMaterials($request->bom_id, $request->cycles),
            'outputs'     => $service->calculateOutputs($request->bom_id, $request->cycles),
            'steps'       => $service->getSteps($request->bom_id),
            'description' => $bom->description,
        ]);
    }

    public function previewScore(Request $request, BomScoreService $scoreService)
    {
        $typicalCycles = (int) ($request->typical_cycles ?? 1);
        $outputs       = $request->outputs ?? [];

        $mainOutput = collect($outputs)->firstWhere('output_type', 'main');
        if (!$mainOutput || empty($mainOutput['product_id'])) {
            return response()->json(['score' => null]);
        }

        $product = Product::find($mainOutput['product_id']);
        if (!$product) {
            return response()->json(['score' => null]);
        }

        $fakeBom = new \App\Modules\Production\Models\Bom([
            'typical_cycles' => $typicalCycles,
        ]);
        $fakeOutput = new \App\Modules\Production\Models\BomOutput([
            'product_id'    => $product->id,
            'qty_per_cycle' => (float) ($mainOutput['qty_per_cycle'] ?? 1),
            'output_type'   => 'main',
        ]);
        $fakeOutput->setRelation('product', $product);
        $fakeBom->setRelation('outputs', collect([$fakeOutput]));

        $score = $scoreService->calculate($fakeBom);
        return response()->json(['score' => $score]);
    }

    public function settings()
    {
        $setting = ProductionSetting::first() ?? new ProductionSetting(['score_sales_period' => 1]);
        $byproducts = ProductionByproduct::with(['product', 'rawMaterial'])->get();
        $rawMaterials = ProductionRawMaterial::with('product')->get();
        $produkAndalan = Product::where('produk_andalan', true)->orderBy('sku')->get(['id', 'sku', 'name']);
        return view('erp.production.settings', compact('setting', 'byproducts', 'rawMaterials', 'produkAndalan'));
    }

    /**
     * Produk andalan = Tingkat 1 skill "Isi antrean CNC" (Karyawan Virtual): dibuatkan OP
     * saat CNC kosong walau stok masih banyak. Hanya produk ready.
     */
    public function updateProdukAndalan(Request $request)
    {
        $request->validate([
            'product_ids'   => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);
        $ids = collect($request->input('product_ids', []))->map(fn ($id) => (int) $id)->filter()->unique();

        $bukanReady = Product::whereIn('id', $ids)->where('sale_type', '!=', 'ready')->pluck('sku');
        if ($bukanReady->isNotEmpty()) {
            return back()->with('error', 'Produk andalan harus produk ready stok: ' . $bukanReady->implode(', ') . '.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($ids) {
            Product::where('produk_andalan', true)->whereNotIn('id', $ids)->update(['produk_andalan' => false]);
            Product::whereIn('id', $ids)->update(['produk_andalan' => true]);
        });

        return back()->with('success', 'Produk andalan disimpan (' . $ids->count() . ' produk).');
    }

    /**
     * Simpan daftar Bahan Baku lembaran + ukuran (PxL) (hapus-lalu-isi-ulang).
     * Dipakai Kalkulator Produk Custom di OP.
     */
    public function updateRawMaterialSettings(Request $request)
    {
        $request->validate([
            'rows'              => 'nullable|array',
            'rows.*.product_id' => 'required|exists:products,id',
            'rows.*.panjang'    => 'required|numeric|gt:0',
            'rows.*.lebar'      => 'required|numeric|gt:0',
        ]);

        $rows = collect($request->input('rows', []))
            ->filter(fn($r) => !empty($r['product_id']));

        // Cegah duplikat produk dalam satu submit.
        $productIds = $rows->pluck('product_id')->map(fn($id) => (int) $id);
        if ($productIds->count() !== $productIds->unique()->count()) {
            return back()->with('error', 'Ada bahan baku yang terdaftar lebih dari satu kali.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($rows) {
            ProductionRawMaterial::query()->delete();
            foreach ($rows as $r) {
                ProductionRawMaterial::create([
                    'product_id' => (int) $r['product_id'],
                    'panjang'    => (float) $r['panjang'],
                    'lebar'      => (float) $r['lebar'],
                ]);
            }
        });

        return back()->with('success', 'Daftar bahan baku disimpan.');
    }

    /**
     * Simpan daftar Produk Sampingan (hapus-lalu-isi-ulang). Hanya produk ready stok
     * yang boleh terdaftar. Persentase = % biaya per unit (basis 1 siklus).
     */
    public function updateByproductSettings(Request $request)
    {
        $request->validate([
            'rows'                          => 'nullable|array',
            'rows.*.product_id'             => 'required|exists:products,id',
            'rows.*.percentage'             => 'nullable|numeric|min:0|max:100',
            'rows.*.raw_material_product_id' => 'nullable|exists:products,id',
            'rows.*.panjang'                => 'nullable|numeric|gt:0',
            'rows.*.lebar'                  => 'nullable|numeric|gt:0',
        ]);

        $rows = collect($request->input('rows', []))
            ->filter(fn($r) => !empty($r['product_id']));

        // Validasi: produk sampingan harus ready stok ATAU custom/preorder.
        $productIds = $rows->pluck('product_id')->map(fn($id) => (int) $id);
        if ($productIds->isNotEmpty()) {
            $invalid = Product::whereIn('id', $productIds)
                ->whereNotIn('sale_type', ['ready', 'preorder'])
                ->pluck('name');
            if ($invalid->isNotEmpty()) {
                return back()->with('error', 'Hanya produk ready stok atau custom/preorder yang boleh jadi produk sampingan: ' . $invalid->implode(', ') . '.');
            }
        }

        // Cegah duplikat produk dalam satu submit.
        if ($productIds->count() !== $productIds->unique()->count()) {
            return back()->with('error', 'Ada produk sampingan yang terdaftar lebih dari satu kali.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($rows) {
            ProductionByproduct::query()->delete();
            foreach ($rows as $r) {
                ProductionByproduct::create([
                    'product_id'              => (int) $r['product_id'],
                    // Kosong = tidak ada % default → wajib diisi manual saat OP.
                    'percentage'              => isset($r['percentage']) && $r['percentage'] !== '' ? (float) $r['percentage'] : null,
                    'raw_material_product_id' => !empty($r['raw_material_product_id']) ? (int) $r['raw_material_product_id'] : null,
                    'panjang'                 => isset($r['panjang']) && $r['panjang'] !== '' ? (float) $r['panjang'] : null,
                    'lebar'                   => isset($r['lebar']) && $r['lebar'] !== '' ? (float) $r['lebar'] : null,
                ]);
            }
        });

        return back()->with('success', 'Daftar produk sampingan disimpan.');
    }

    public function updateSettings(Request $request)
    {
        // period_choice: week | 1 | 2 | 3 | range
        $request->validate([
            'period_choice' => 'required|in:week,1,2,3,range',
            'period_start'  => 'required_if:period_choice,range|nullable|date',
            'period_end'    => 'required_if:period_choice,range|nullable|date|after_or_equal:period_start',
        ]);

        $choice = $request->period_choice;

        if ($choice === 'week') {
            ProductionSetting::savePeriod('week');
        } elseif ($choice === 'range') {
            ProductionSetting::savePeriod('range', 1, $request->period_start, $request->period_end);
        } else {
            ProductionSetting::savePeriod('months', (int) $choice);
        }

        return back()->with('success', 'Pengaturan produksi disimpan.');
    }

    /** Toggle mode testing produksi (start/lanjut task tanpa scan sidik jari). */
    public function updateTestingMode(Request $request)
    {
        $setting = ProductionSetting::first() ?? new ProductionSetting(['score_sales_period' => 1]);
        $setting->testing_mode = $request->boolean('testing_mode');
        $setting->save();

        return back()->with('success', $setting->testing_mode
            ? 'Mode testing AKTIF — start/lanjut task tidak butuh scan sidik jari.'
            : 'Mode testing dimatikan — scan sidik jari kembali diwajibkan.');
    }

    /** Trigger manual: hitung ulang score semua BOM aktif (logic di BomScoreService). */
    public function recalculate(BomScoreService $scoreService)
    {
        $scoreService->recalculateAll();
        return back()->with('success', 'Score semua BOM berhasil dihitung ulang.');
    }
}
