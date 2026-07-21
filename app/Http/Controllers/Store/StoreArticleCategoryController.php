<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreArticleCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreArticleCategoryController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q'));

        $categories = StoreArticleCategory::query()
            ->withCount('articles')
            ->when($q !== '', fn($qq) => $qq->where(function ($w) use ($q) {
                $w->where('name', 'like', "%$q%")->orWhere('slug', 'like', "%$q%");
            }))
            ->orderBy('sort_order')->orderBy('name')
            ->paginate(per_page_size())
            ->withQueryString();

        return view('erp.store.article-categories.index', compact('categories', 'q'));
    }

    public function create()
    {
        return view('erp.store.article-categories.create');
    }

    public function store(Request $request)
    {
        StoreArticleCategory::create($this->validateData($request));
        return redirect(list_url('store.article-categories.index'))->with('success', 'Kategori artikel dibuat.');
    }

    public function edit($id)
    {
        $category = StoreArticleCategory::findOrFail($id);
        return view('erp.store.article-categories.edit', compact('category'));
    }

    public function update(Request $request, $id)
    {
        $category = StoreArticleCategory::findOrFail($id);
        $category->update($this->validateData($request, $category->id));
        return redirect(list_url('store.article-categories.index'))->with('success', 'Kategori artikel diperbarui.');
    }

    public function destroy($id)
    {
        StoreArticleCategory::findOrFail($id)->delete();
        return back()->with('success', 'Kategori artikel dihapus.');
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'slug'       => ['nullable', 'string', 'max:255', 'alpha_dash',
                              Rule::unique('store_article_categories', 'slug')->ignore($ignoreId)],
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
        while (StoreArticleCategory::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
