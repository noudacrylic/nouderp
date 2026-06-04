<?php

namespace App\Modules\FixedAsset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAsset\Models\AssetCategory;
use App\Core\Accounting\Account;
use Illuminate\Http\Request;

class AssetCategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = AssetCategory::with([
            'fixedAssetAccount', 'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
        ])
        ->when($request->q, function ($q, $term) {
            $q->where(function ($w) use ($term) {
                $w->where('code', 'like', "%{$term}%")
                  ->orWhere('name', 'like', "%{$term}%");
            });
        })
        ->orderBy('code')
        ->paginate(25)
        ->withQueryString();

        return view('erp.fixed-assets.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('erp.fixed-assets.categories.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        try {
            // Auto-generate code & code_prefix dari nama. Kedua field di-derive dari 3 huruf alfabet pertama (uppercase),
            // dengan suffix angka kalau collision. User cukup input nama saja.
            $generated = $this->generateCodeFromName($data['name']);
            $data['code'] = $generated;
            $data['code_prefix'] = $generated;

            AssetCategory::create($data);
            return redirect(list_url('fixed-assets.categories.index'))->with('success', 'Kategori aset dibuat.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function edit($id)
    {
        $category = AssetCategory::findOrFail($id);
        return view('erp.fixed-assets.categories.edit', compact('category'));
    }

    public function update(Request $request, $id)
    {
        $category = AssetCategory::findOrFail($id);
        $data = $this->validateData($request, $id);

        try {
            // Code & code_prefix tetap dari saat create — tidak boleh berubah karena sudah dipakai di asset_code aset existing.
            unset($data['code'], $data['code_prefix']);

            $category->update($data);
            return redirect(list_url('fixed-assets.categories.index'))->with('success', 'Kategori aset diupdate.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $category = AssetCategory::findOrFail($id);
        if (!$category->canBeDeleted()) {
            return back()->with('error', 'Kategori sudah dipakai aset, tidak bisa dihapus.');
        }
        $category->delete();
        return redirect(list_url('fixed-assets.categories.index'))->with('success', 'Kategori dihapus.');
    }

    public function getDefaults($id)
    {
        $category = AssetCategory::findOrFail($id);
        return response()->json([
            'default_useful_life_months' => $category->default_useful_life_months,
            'default_salvage_value_percent' => $category->default_salvage_value_percent,
            'is_depreciable_default' => $category->is_depreciable_default,
            'code_prefix' => $category->code_prefix,
        ]);
    }

    protected function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'default_useful_life_months' => 'required|integer|min:1',
            'default_salvage_value_percent' => 'nullable|numeric|min:0|max:100',
            'is_depreciable_default' => 'nullable|boolean',
            'fixed_asset_account_id' => 'required|exists:accounts,id',
            'accumulated_depreciation_account_id' => 'nullable|exists:accounts,id',
            'depreciation_expense_account_id' => 'nullable|exists:accounts,id',
            'is_active' => 'nullable|boolean',
        ]);
    }

    /**
     * Bikin kode kategori dari 3 huruf alfabet pertama nama (uppercase), append angka kalau collision.
     * Contoh: "Mesin & Peralatan" → MES; jika MES sudah ada → MES2; MES2 ada → MES3.
     * Code dipakai 1:1 sebagai code_prefix (juga komponen asset_code FA/{prefix}/yyyy/nnnn).
     */
    protected function generateCodeFromName(string $name): string
    {
        $alphaOnly = preg_replace('/[^A-Za-z]/', '', $name);
        $base = strtoupper(substr($alphaOnly, 0, 3));
        if (strlen($base) < 2) {
            // Fallback kalau nama tidak punya cukup huruf alfabet (mis. angka saja)
            $base = 'KAT';
        }

        $candidate = $base;
        $suffix = 1;
        while (
            AssetCategory::withTrashed()
                ->where(function ($q) use ($candidate) {
                    $q->where('code', $candidate)->orWhere('code_prefix', $candidate);
                })
                ->exists()
        ) {
            $suffix++;
            $candidate = $base . $suffix;
        }

        return $candidate;
    }
}
