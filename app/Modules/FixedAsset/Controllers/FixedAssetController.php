<?php

namespace App\Modules\FixedAsset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAsset\Models\AssetCategory;
use App\Modules\FixedAsset\Models\FixedAsset;
use App\Modules\FixedAsset\Services\FixedAssetService;
use App\Modules\FixedAsset\Services\FixedAssetDepreciationService;
use App\Core\Inventory\Warehouse;
use Illuminate\Http\Request;

class FixedAssetController extends Controller
{
    public function __construct(
        protected FixedAssetService $service,
        protected FixedAssetDepreciationService $depService
    ) {}

    public function index(Request $request)
    {
        $assets = FixedAsset::with(['category', 'warehouse', 'sourceInvoice'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->category_id, fn($q, $c) => $q->where('asset_category_id', $c))
            ->when($request->warehouse_id, fn($q, $w) => $q->where('warehouse_id', $w))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('acquisition_date', '>=', $d))
            ->when($request->date_to, fn($q, $d) => $q->whereDate('acquisition_date', '<=', $d))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('asset_code', 'like', "%{$term}%")
                      ->orWhere('name', 'like', "%{$term}%")
                      ->orWhere('serial_number', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('acquisition_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $categories = AssetCategory::where('is_active', 1)->orderBy('name')->get();
        $warehouses = Warehouse::where('is_active', 1)->orderBy('name')->get();

        // Total beban penyusutan bulanan dari seluruh aset aktif & depreciable.
        // Pakai calculateMonthlyAmount() supaya konsisten dgn runner (handle clamp di akhir umur).
        $monthlyDepreciationTotal = FixedAsset::where('status', 'active')
            ->where('is_depreciable', true)
            ->where('useful_life_months', '>', 0)
            ->get()
            ->sum(fn($a) => $this->depService->calculateMonthlyAmount($a));

        return view('erp.fixed-assets.index', compact('assets', 'categories', 'warehouses', 'monthlyDepreciationTotal'));
    }

    public function create()
    {
        $categories = AssetCategory::where('is_active', 1)->orderBy('name')->get();
        $warehouses = Warehouse::where('is_active', 1)->orderBy('name')->get();
        return view('erp.fixed-assets.create', compact('categories', 'warehouses'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $action = $request->input('_after_save', '');
        try {
            $asset = $this->service->createDraft($data);
            if ($action === 'post') {
                $this->service->post($asset->refresh());
            }
            $msg = $action === 'post' ? 'Aset dibuat & POSTED.' : 'Aset draft dibuat.';
            return redirect(list_url('fixed-assets.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $asset = FixedAsset::with([
            'category', 'warehouse', 'sourceInvoice',
            'depreciations.period', 'depreciations.journal',
            'transfers.fromWarehouse', 'transfers.toWarehouse',
            'disposals.proceedsAccount',
            'acquisitionJournal.lines.account',
            'disposalJournal.lines.account',
        ])->findOrFail($id);
        return view('erp.fixed-assets.show', compact('asset'));
    }

    public function edit($id)
    {
        $asset = FixedAsset::with('category')->findOrFail($id);
        if (!$asset->canBeEdited()) {
            return redirect()->route('fixed-assets.show', $asset->id)
                ->with('error', 'Hanya aset draft yang bisa diedit.');
        }
        $categories = AssetCategory::where('is_active', 1)->orderBy('name')->get();
        $warehouses = Warehouse::where('is_active', 1)->orderBy('name')->get();
        return view('erp.fixed-assets.edit', compact('asset', 'categories', 'warehouses'));
    }

    public function update(Request $request, $id)
    {
        $asset = FixedAsset::findOrFail($id);
        $data = $this->validateData($request, isUpdate: true);

        $action = $request->input('_after_save', '');
        try {
            $this->service->update($asset, $data);
            if ($action === 'post') {
                $this->service->post($asset->refresh());
            }
            $msg = $action === 'post' ? 'Aset diupdate & POSTED.' : 'Aset diupdate (draft).';
            return redirect(list_url('fixed-assets.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $asset = FixedAsset::findOrFail($id);
        try {
            $this->service->destroy($asset);
            return redirect(list_url('fixed-assets.index'))->with('success', 'Aset draft dihapus.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post($id)
    {
        $asset = FixedAsset::findOrFail($id);
        try {
            $this->service->post($asset);
            return back()->with('success', 'Aset POSTED — sekarang aktif.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $asset = FixedAsset::findOrFail($id);
        try {
            $this->service->void($asset);
            return back()->with('success', 'Aset di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function searchActive(Request $request)
    {
        $term = trim((string) $request->get('q', ''));
        $assets = FixedAsset::with('category', 'warehouse')
            ->where('status', 'active')
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($w) use ($term) {
                    $w->where('asset_code', 'like', "%{$term}%")
                      ->orWhere('name', 'like', "%{$term}%");
                });
            })
            ->orderBy('asset_code')
            ->limit(25)
            ->get(['id', 'asset_code', 'name', 'asset_category_id', 'warehouse_id', 'acquisition_cost', 'accumulated_depreciation', 'current_book_value']);

        return response()->json($assets->map(fn($a) => [
            'id' => $a->id,
            'asset_code' => $a->asset_code,
            'name' => $a->name,
            'category' => $a->category->name ?? '',
            'warehouse_id' => $a->warehouse_id,
            'warehouse' => $a->warehouse->name ?? '',
            'acquisition_cost' => (float) $a->acquisition_cost,
            'accumulated_depreciation' => (float) $a->accumulated_depreciation,
            'book_value' => (float) $a->current_book_value,
        ]));
    }

    protected function validateData(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'asset_category_id' => 'required|exists:asset_categories,id',
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:500',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'responsible_person' => 'nullable|string|max:150',
            'serial_number' => 'nullable|string|max:100',
            'acquisition_date' => 'required|date',
            'acquisition_cost' => 'required|numeric|gt:0',
            'salvage_value' => 'nullable|numeric|min:0',
            'useful_life_months' => 'nullable|integer|min:1',
            'is_depreciable' => 'nullable|boolean',
            'depreciation_start_date' => 'nullable|date',
            // last_depreciation_date, accumulated_depreciation, months_depreciated tidak divalidasi/diterima dari client —
            // dihitung server di FixedAssetService::computeDepreciationFields().
            'notes' => 'nullable|string|max:1000',
        ];
        return $request->validate($rules);
    }
}
