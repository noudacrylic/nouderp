<?php

namespace App\Http\Controllers\Store;

use App\Core\Inventory\Product;
use App\Http\Controllers\Controller;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreProductController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q'));
        $status = $request->get('status');

        $products = StoreProduct::query()
            ->with('category')
            ->withCount('variants')
            ->when($q !== '', fn($qq) => $qq->where(function ($w) use ($q) {
                $w->where('name', 'like', "%$q%")->orWhere('slug', 'like', "%$q%");
            }))
            ->when(in_array($status, ['draft', 'published'], true), fn($qq) => $qq->where('status', $status))
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(per_page_size())
            ->withQueryString();

        return view('erp.store.products.index', compact('products', 'q', 'status'));
    }

    public function create()
    {
        $categories = StoreCategory::orderBy('name')->get();
        return view('erp.store.products.create', compact('categories'));
    }

    /**
     * Langkah awal: cukup nama (+ kategori) → buat DRAFT supaya dapat id, lalu
     * lanjut ke halaman edit (satu halaman) untuk lengkapi detail, varian & galeri.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'store_category_id' => ['nullable', 'integer', 'exists:store_categories,id'],
        ]);

        $product = StoreProduct::create([
            'name'              => $data['name'],
            'slug'              => $this->uniqueSlug($data['name']),
            'store_category_id' => $data['store_category_id'] ?? null,
            'status'            => 'draft',
        ]);

        return redirect()->route('store.products.edit', $product->id)
            ->with('success', 'Draft dibuat. Lengkapi detail, varian, dan galeri foto/video di bawah.');
    }

    public function edit($id)
    {
        $product = StoreProduct::with(['variants.product', 'media'])->findOrFail($id);
        $categories = StoreCategory::orderBy('name')->get();
        $variants = $product->variants;
        return view('erp.store.products.edit', compact('product', 'categories', 'variants'));
    }

    public function update(Request $request, $id)
    {
        $product = StoreProduct::findOrFail($id);

        // Status ditentukan tombol yang diklik: "Terbitkan" → published, lainnya → draft.
        $status = $request->input('action') === 'publish' ? 'published' : 'draft';
        $data = $this->validateData($request, $product->id, $status);

        DB::transaction(function () use ($product, $data) {
            $product->update($data['product']);
            // Sinkron varian: hapus lama, buat ulang dari submission (dalam 1 transaksi
            // sehingga unique product_id tidak bentrok untuk SKU yang sama).
            $product->variants()->delete();
            $this->syncVariants($product, $data['variants']);
        });

        $msg = $status === 'published' ? 'Produk Store diterbitkan.' : 'Draft disimpan.';
        return redirect(list_url('store.products.index'))->with('success', $msg);
    }

    public function destroy($id)
    {
        $product = StoreProduct::findOrFail($id);
        // Hapus file media dari disk dulu (baris media ikut cascade FK).
        app(\App\Services\Store\StoreMediaService::class)->purgeFilesFor($product);
        $product->delete(); // varian & media cascade via FK
        return back()->with('success', 'Produk Store dihapus.');
    }

    /**
     * Validasi + normalisasi. Return ['product' => [...], 'variants' => [...]].
     * Dua mode:
     *  - 'single'  : produk tanpa varian → 1 SKU.
     *  - 'variant' : variasi ala Shopee (1-2 sumbu, mis. Orientasi × Packing);
     *                tiap kombinasi opsi dipetakan ke 1 SKU.
     * Aturan SKU (is_sellable, unik, kelengkapan kombinasi) hanya saat publish.
     */
    private function validateData(Request $request, ?int $ignoreId, string $status): array
    {
        $publishing = $status === 'published';

        $validated = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'slug'              => ['nullable', 'string', 'max:255', 'alpha_dash',
                                    Rule::unique('store_products', 'slug')->ignore($ignoreId)],
            'store_category_id' => ['nullable', 'integer', 'exists:store_categories,id'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description'       => ['nullable', 'string'],
            'meta_title'        => ['nullable', 'string', 'max:255'],
            'meta_description'  => ['nullable', 'string', 'max:255'],
            'is_featured'       => ['nullable', 'boolean'],
            'sort_order'        => ['nullable', 'integer'],
            'variant_mode'      => ['required', Rule::in(['single', 'variant'])],
        ]);

        $product = [
            'name'              => $validated['name'],
            'slug'              => !empty($validated['slug']) ? Str::slug($validated['slug']) : $this->uniqueSlug($validated['name'], $ignoreId),
            'store_category_id' => $validated['store_category_id'] ?? null,
            'short_description' => $validated['short_description'] ?? null,
            'description'       => $validated['description'] ?? null,
            'meta_title'        => $validated['meta_title'] ?? null,
            'meta_description'  => $validated['meta_description'] ?? null,
            'status'            => $status,
            'is_featured'       => $request->boolean('is_featured'),
            'sort_order'        => $validated['sort_order'] ?? 0,
            'variant_axes'      => null,
        ];

        return $validated['variant_mode'] === 'single'
            ? $this->buildSingle($request, $product, $ignoreId, $publishing)
            : $this->buildVariant($request, $product, $ignoreId, $publishing);
    }

    /** Mode tanpa varian: 1 SKU. */
    private function buildSingle(Request $request, array $product, ?int $ignoreId, bool $publishing): array
    {
        $data = $request->validate([
            'single_product_id' => [$publishing ? 'required' : 'nullable', 'integer', 'exists:products,id'],
        ], ['single_product_id.required' => 'Untuk diterbitkan, pilih 1 SKU produk.']);

        $product['variant_axes'] = null;
        $pid = $data['single_product_id'] ?? null;
        if (!$pid) {
            return ['product' => $product, 'variants' => []];
        }

        $this->guardSkus([(int) $pid], $ignoreId);

        return ['product' => $product, 'variants' => [[
            'product_id'    => (int) $pid,
            'variant_label' => null,
            'option_values' => null,
            'is_default'    => true,
            'sort_order'    => 0,
        ]]];
    }

    /** Mode varian (Shopee-style): sumbu + kombinasi → SKU. */
    private function buildVariant(Request $request, array $product, ?int $ignoreId, bool $publishing): array
    {
        $data = $request->validate([
            'axes'                  => [$publishing ? 'required' : 'nullable', 'array', 'max:2'],
            'axes.*.name'           => ['required', 'string', 'max:255'],
            'axes.*.options'        => ['required', 'array', 'min:1'],
            'axes.*.options.*'      => ['required', 'string', 'max:255'],
            'variants'                  => ['nullable', 'array'],
            'variants.*.product_id'     => ['required', 'integer', 'distinct', 'exists:products,id'],
            'variants.*.options'        => ['required', 'array'],
            'variants.*.options.*'      => ['nullable', 'string', 'max:255'],
            'variants.*.image_media_id' => ['nullable', 'integer'],
            'default_index'             => ['nullable', 'integer'],
        ], [
            'axes.required' => 'Untuk diterbitkan, tambahkan minimal 1 variasi beserta opsinya.',
            'variants.*.product_id.distinct' => 'Ada SKU yang dipakai lebih dari satu kombinasi.',
        ]);

        // Normalisasi sumbu (buang opsi kosong/duplikat).
        $axes = [];
        foreach (array_values($data['axes'] ?? []) as $ax) {
            $opts = array_values(array_unique(array_filter(array_map('trim', $ax['options']), fn($o) => $o !== '')));
            if (trim($ax['name']) !== '' && count($opts)) {
                $axes[] = ['name' => trim($ax['name']), 'options' => $opts];
            }
        }
        $product['variant_axes'] = $axes ?: null;

        $rawVariants = array_values($data['variants'] ?? []);
        $productIds = array_map(fn($v) => (int) $v['product_id'], $rawVariants);
        if (!empty($productIds)) {
            $this->guardSkus($productIds, $ignoreId);
        }

        // Publish: semua kombinasi (cartesian) wajib punya SKU.
        if ($publishing) {
            $expected = array_product(array_map(fn($ax) => count($ax['options']), $axes)) ?: 0;
            if ($expected === 0 || count($rawVariants) < $expected) {
                throw ValidationException::withMessages([
                    'variants' => "Lengkapi SKU untuk semua kombinasi varian ({$expected} kombinasi).",
                ]);
            }
        }

        $defaultIndex = (int) ($data['default_index'] ?? 0);
        $variants = [];
        foreach ($rawVariants as $i => $v) {
            $opts = array_values(array_map('trim', $v['options']));
            $variants[] = [
                'product_id'     => (int) $v['product_id'],
                'variant_label'  => implode(' / ', $opts),
                'option_values'  => $opts,
                'image_media_id' => !empty($v['image_media_id']) ? (int) $v['image_media_id'] : null,
                'is_default'     => $i === $defaultIndex,
                'sort_order'     => $i,
            ];
        }
        if (count($variants) && !collect($variants)->contains('is_default', true)) {
            $variants[0]['is_default'] = true;
        }

        return ['product' => $product, 'variants' => $variants];
    }

    /** Gerbang SKU: wajib is_sellable & aktif, dan 1 SKU maks di 1 Produk Store. */
    private function guardSkus(array $productIds, ?int $ignoreId): void
    {
        $eligible = Product::whereIn('id', $productIds)
            ->where('is_active', true)->where('is_sellable', true)
            ->pluck('id')->all();
        if (array_diff($productIds, $eligible)) {
            throw ValidationException::withMessages([
                'variants' => 'Ada SKU yang tidak ditandai "Dijual" (is_sellable) atau tidak aktif.',
            ]);
        }

        $taken = StoreProductVariant::whereIn('product_id', $productIds)
            ->when($ignoreId, fn($q) => $q->where('store_product_id', '!=', $ignoreId))
            ->pluck('product_id')->all();
        if (!empty($taken)) {
            throw ValidationException::withMessages([
                'variants' => 'Ada SKU yang sudah dipakai Produk Store lain: ' . implode(', ', $taken),
            ]);
        }
    }

    private function syncVariants(StoreProduct $product, array $variants): void
    {
        foreach ($variants as $v) {
            $product->variants()->create($v);
        }
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'produk';
        $slug = $base;
        $i = 2;
        while (StoreProduct::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
