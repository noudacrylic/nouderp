<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCategoryController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q'));

        $categories = StoreCategory::query()
            ->with('parent')
            ->withCount('products')
            ->when($q !== '', fn($qq) => $qq->where(function ($w) use ($q) {
                $w->where('name', 'like', "%$q%")->orWhere('slug', 'like', "%$q%");
            }))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(per_page_size())
            ->withQueryString();

        return view('erp.store.categories.index', compact('categories', 'q'));
    }

    public function create()
    {
        $parents = StoreCategory::orderBy('name')->get();
        return view('erp.store.categories.create', compact('parents'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        StoreCategory::create($data);

        return redirect(list_url('store.categories.index'))
            ->with('success', 'Kategori berhasil dibuat.');
    }

    public function edit($id)
    {
        $category = StoreCategory::findOrFail($id);
        // Cegah jadikan diri sendiri sebagai induk.
        $parents = StoreCategory::where('id', '!=', $id)->orderBy('name')->get();
        return view('erp.store.categories.edit', compact('category', 'parents'));
    }

    public function update(Request $request, $id)
    {
        $category = StoreCategory::findOrFail($id);
        $data = $this->validateData($request, $category->id);

        // Guard: induk tidak boleh dirinya sendiri.
        if ((int) ($data['parent_id'] ?? 0) === (int) $category->id) {
            $data['parent_id'] = null;
        }

        $category->update($data);

        return redirect(list_url('store.categories.index'))
            ->with('success', 'Kategori berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $category = StoreCategory::findOrFail($id);
        // Produk yang memakai kategori ini akan kehilangan kategori (nullOnDelete), tidak terhapus.
        $category->delete();

        return back()->with('success', 'Kategori dihapus.');
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'slug'       => ['nullable', 'string', 'max:255', 'alpha_dash',
                              Rule::unique('store_categories', 'slug')->ignore($ignoreId)],
            'parent_id'  => ['nullable', 'integer', 'exists:store_categories,id'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $validated['slug'] = !empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : $this->uniqueSlug($validated['name'], $ignoreId);
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        return $validated;
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'kategori';
        $slug = $base;
        $i = 2;
        while (StoreCategory::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
