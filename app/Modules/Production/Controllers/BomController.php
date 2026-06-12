<?php

namespace App\Modules\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\BomScoreService;
use App\Modules\Production\Services\AutoProductionService;
use App\Models\ProductionSetting;
use App\Modules\Production\Models\ProductionByproduct;
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

    public function runAuto(AutoProductionService $auto)
    {
        $results = $auto->runAll();

        $created = collect($results)->where('created', true);
        $skipped = collect($results)->where('created', false);

        if ($results === []) {
            return back()->with('info', 'Belum ada BOM dengan auto-produksi yang aktif.');
        }

        if ($created->isEmpty()) {
            $first = $skipped->take(3)->pluck('reason')->join(' · ');
            return back()->with('info', "Tidak ada order baru. Diperiksa {$skipped->count()} BOM. " . $first);
        }

        $list = $created->map(fn($r) => "{$r['order_number']} ({$r['bom_number']})")->join(', ');
        return back()->with('success', "{$created->count()} order otomatis dibuat: {$list}.");
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
        return ProductionByproduct::with('product')->get()->map(fn($b) => [
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
        $byproducts = ProductionByproduct::with('product')->get();
        return view('erp.production.settings', compact('setting', 'byproducts'));
    }

    /**
     * Simpan daftar Produk Sampingan (hapus-lalu-isi-ulang). Hanya produk ready stok
     * yang boleh terdaftar. Persentase = % biaya per unit (basis 1 siklus).
     */
    public function updateByproductSettings(Request $request)
    {
        $request->validate([
            'rows'              => 'nullable|array',
            'rows.*.product_id' => 'required|exists:products,id',
            'rows.*.percentage' => 'required|numeric|min:0|max:100',
        ]);

        $rows = collect($request->input('rows', []))
            ->filter(fn($r) => !empty($r['product_id']));

        // Validasi: semua produk wajib ready stok.
        $productIds = $rows->pluck('product_id')->map(fn($id) => (int) $id);
        if ($productIds->isNotEmpty()) {
            $nonReady = Product::whereIn('id', $productIds)
                ->where('sale_type', '!=', 'ready')
                ->pluck('name');
            if ($nonReady->isNotEmpty()) {
                return back()->with('error', 'Hanya produk ready stok yang boleh jadi produk sampingan: ' . $nonReady->implode(', ') . '.');
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
                    'product_id' => (int) $r['product_id'],
                    'percentage' => (float) $r['percentage'],
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

    /** Trigger manual: hitung ulang score semua BOM aktif (logic di BomScoreService). */
    public function recalculate(BomScoreService $scoreService)
    {
        $scoreService->recalculateAll();
        return back()->with('success', 'Score semua BOM berhasil dihitung ulang.');
    }
}
