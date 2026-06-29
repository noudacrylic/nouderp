<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Http\Controllers\Controller;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Modules\Sales\Models\Promotion;
use App\Modules\Sales\Services\PromotionService;
use Illuminate\Http\Request;

/**
 * API baca jembatan etalase (noudakrilik.com). Dikonsumsi VPS/etalase server-side,
 * digerbangi middleware storefront.api (kunci rahasia). ERP = sumber kebenaran:
 * katalog/harga boleh di-cache di sisi etalase, STOK ditarik live via /stock.
 */
class StorefrontController extends Controller
{
    public function __construct(private PromotionService $promotions) {}

    /** Pohon kategori untuk navigasi. */
    public function categories()
    {
        $cats = StoreCategory::orderBy('sort_order')->orderBy('name')
            ->get(['id', 'parent_id', 'slug', 'name', 'sort_order']);

        return response()->json(['data' => $cats]);
    }

    /**
     * Daftar produk terbit (untuk isi cache katalog). Dukung ?updated_since=ISO
     * (inkremental) & ?page=. Stok TIDAK disertakan (ambil live via /stock).
     */
    public function products(Request $request)
    {
        $query = StoreProduct::query()
            ->published()
            ->with(['category', 'media', 'variants.product'])
            ->orderBy('sort_order')->orderByDesc('id');

        if ($since = $request->get('updated_since')) {
            $query->where('updated_at', '>=', $since);
        }

        $page = $query->paginate(min((int) $request->get('per_page', 100), 200))->withQueryString();

        // Hitung diskon item sekali untuk semua varian di halaman ini.
        $discounts = $this->itemDiscounts($page->getCollection());

        return response()->json([
            'data' => $page->getCollection()->map(fn($p) => $this->serializeProduct($p, $discounts))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    /** Detail satu produk terbit by slug. */
    public function product(string $slug)
    {
        $p = StoreProduct::published()
            ->with(['category', 'media', 'variants.product'])
            ->where('slug', $slug)
            ->first();

        if (!$p) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $this->serializeProduct($p, $this->itemDiscounts(collect([$p])))]);
    }

    /**
     * Stok LIVE per SKU (product_id ERP). ?product_ids=1,2,3
     * available = gudang sellable on_hand − reservasi aktif + preorder_stock.
     */
    public function stock(Request $request)
    {
        $ids = collect(explode(',', (string) $request->get('product_ids')))
            ->map(fn($v) => (int) trim($v))->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $onHand = ProductStock::whereIn('product_id', $ids)
            ->whereHas('warehouse', fn($q) => $q->where('is_sellable', true))
            ->selectRaw('product_id, SUM(qty_on_hand) as qty')
            ->groupBy('product_id')->pluck('qty', 'product_id');

        $reserved = StockReservation::whereIn('product_id', $ids)
            ->where('status', 'active')
            ->selectRaw('product_id, SUM(qty) as qty')
            ->groupBy('product_id')->pluck('qty', 'product_id');

        $preorder = Product::whereIn('id', $ids)->pluck('preorder_stock', 'id');

        $data = $ids->map(fn($id) => [
            'product_id' => $id,
            'available'  => max(0, (float) ($onHand[$id] ?? 0) - (float) ($reserved[$id] ?? 0) + (float) ($preorder[$id] ?? 0)),
        ]);

        return response()->json(['data' => $data]);
    }

    /** Promosi aktif (untuk harga coret & info diskon di etalase). */
    public function promotions()
    {
        $promos = Promotion::active()->with('tiers')->get()->map(fn(Promotion $p) => [
            'id'             => $p->id,
            'type'           => $p->type,          // item | shipping | cart_total
            'name'           => $p->name,
            'discount_type'  => $p->discount_type, // nominal | percent
            'discount_value' => (float) $p->discount_value,
            'applies_to_all' => (bool) $p->applies_to_all,
            'product_ids'    => $p->type === 'item' ? $p->productIds() : [],
            'starts_at'      => optional($p->starts_at)->toDateString(),
            'ends_at'        => optional($p->ends_at)->toDateString(),
            'tiers'          => $p->tiers->map(fn($t) => [
                'min_spend'      => (float) $t->min_spend,
                'discount_type'  => $t->discount_type,
                'discount_value' => (float) $t->discount_value,
            ])->values(),
        ]);

        return response()->json(['data' => $promos]);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** Map product_id → info diskon item (harga coret) untuk semua varian di koleksi. */
    private function itemDiscounts($products): array
    {
        $items = [];
        foreach ($products as $p) {
            foreach ($p->variants as $v) {
                if (!$v->product) continue;
                $items[] = [
                    'product_id' => $v->product_id,
                    'qty'        => 1,
                    'unit_price' => (float) ($v->product->display_price ?? 0),
                ];
            }
        }
        return $items ? $this->promotions->resolveItemDiscounts($items) : [];
    }

    private function serializeProduct(StoreProduct $p, array $discounts): array
    {
        $variants = $p->variants->map(function ($v) use ($discounts) {
            $price = (float) ($v->product->display_price ?? 0);
            $disc  = $discounts[$v->product_id] ?? null;
            $final = $disc ? max(0, $price - (float) $disc['discount_amount']) : $price;

            return [
                'id'             => $v->id,
                'sku'            => $v->product->sku ?? null,
                'product_id'     => $v->product_id,
                'label'          => $v->variant_label,
                'options'        => $v->option_values,
                'is_default'     => (bool) $v->is_default,
                'price'          => $final,
                'price_original' => $price,
                'discount'       => $disc ? [
                    'name'   => $disc['promotion_name'],
                    'amount' => (float) $disc['discount_amount'],
                ] : null,
            ];
        });

        return [
            'id'                => $p->id,
            'slug'              => $p->slug,
            'name'              => $p->name,
            'short_description' => $p->short_description,
            'description'       => $p->description,
            'is_featured'       => (bool) $p->is_featured,
            'sort_order'        => $p->sort_order,
            'category'          => $p->category ? [
                'id' => $p->category->id, 'slug' => $p->category->slug, 'name' => $p->category->name,
            ] : null,
            'has_variants'      => $p->hasVariants(),
            'variant_axes'      => $p->variant_axes,
            'media'             => $p->media->map(fn($m) => [
                'kind'       => $m->kind,
                'source'     => $m->source,
                'url'        => $m->url,
                'alt'        => $m->alt_text,
                'is_primary' => (bool) $m->is_primary,
                'sort_order' => $m->sort_order,
            ])->values(),
            'variants'          => $variants->values(),
            'price_from'        => $variants->min('price'),
            'meta'              => [
                'title'       => $p->meta_title ?: $p->name,
                'description' => $p->meta_description ?: $p->short_description,
            ],
            'updated_at'        => optional($p->updated_at)->toIso8601String(),
        ];
    }
}
