<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Http\Controllers\Controller;
use App\Models\StoreArticle;
use App\Models\StoreArticleCategory;
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
     * Catat 1 kali produk dilihat pengunjung. Increment via query builder mentah
     * agar TIDAK menyentuh updated_at (cache katalog tak ikut ter-resync).
     */
    public function recordView(string $slug)
    {
        $p = StoreProduct::published()->where('slug', $slug)->first(['id']);
        if ($p) {
            \Illuminate\Support\Facades\DB::table('store_products')
                ->where('id', $p->id)->increment('view_count');
        }
        return response()->noContent(); // 204
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

        // Muat produk + relasi bundle → tahu mana SKU bundle & komponennya.
        $products = Product::whereIn('id', $ids)
            ->with(['bundleItems', 'bundleComponents'])
            ->get()->keyBy('id');

        // Kumpulkan semua product_id relevan: SKU diminta + komponen bundle
        // (stok bundle dihitung dari stok komponennya, bukan on_hand sendiri).
        $allIds = collect($ids);
        foreach ($products as $p) {
            if ($p->sale_type === 'bundle') {
                $components = $p->bundleItems->isNotEmpty() ? $p->bundleItems : $p->bundleComponents;
                $allIds = $allIds->merge($components->pluck('component_product_id'));
            }
        }
        $allIds = $allIds->filter()->unique()->values();

        $onHand = ProductStock::whereIn('product_id', $allIds)
            ->whereHas('warehouse', fn($q) => $q->where('is_sellable', true))
            ->selectRaw('product_id, SUM(qty_on_hand) as qty')
            ->groupBy('product_id')->pluck('qty', 'product_id');

        $reserved = StockReservation::whereIn('product_id', $allIds)
            ->where('status', 'active')
            ->selectRaw('product_id, SUM(qty) as qty')
            ->groupBy('product_id')->pluck('qty', 'product_id');

        $sold = $this->soldCounts($ids);

        $data = $ids->map(function ($id) use ($products, $onHand, $reserved, $sold) {
            $p = $products->get($id);

            // SKU bundle: tersedia = MIN antar komponen floor((sellable − reservasi) / qty_dibutuhkan).
            if ($p && $p->sale_type === 'bundle') {
                $components = $p->bundleItems->isNotEmpty() ? $p->bundleItems : $p->bundleComponents;
                $avail = null;
                foreach ($components as $c) {
                    $req = (float) ($c->qty_required ?? $c->qty ?? 1);
                    if ($req <= 0) continue;
                    $cid  = $c->component_product_id;
                    $free = (float) ($onHand[$cid] ?? 0) - (float) ($reserved[$cid] ?? 0);
                    $byComp = (int) floor(max(0, $free) / $req);
                    $avail = is_null($avail) ? $byComp : min($avail, $byComp);
                }
                return ['product_id' => $id, 'available' => max(0, $avail ?? 0), 'sold' => (int) ($sold[$id] ?? 0)];
            }

            // Produk biasa / preorder: on_hand sellable − reservasi + preorder_stock.
            $preorder = $p->preorder_stock ?? 0;
            return [
                'product_id' => $id,
                'available'  => max(0, (float) ($onHand[$id] ?? 0) - (float) ($reserved[$id] ?? 0) + (float) $preorder),
                'sold'       => (int) ($sold[$id] ?? 0),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Jumlah unit terjual per SKU, sepanjang waktu — untuk bukti sosial di etalase.
     *
     * Dasarnya ITEM FAKTUR berstatus posted, bukan pesanan penjualan: pesanan masih
     * bisa batal, sedangkan faktur berarti barang benar-benar keluar. Basis ini juga
     * otomatis mencakup semua kanal — web, marketplace (Jubelio), kasir POS yang
     * menerbitkan faktur tanpa SO, dan penjualan offline.
     *
     * Retur TIDAK dikurangkan (keputusan 2 Agu 2026): selisihnya kecil dan tidak
     * sebanding dengan biaya query tambahan di jalur yang dipanggil tiap halaman
     * produk dibuka.
     *
     * @return array<int,int> product_id => qty
     */
    private function soldCounts(\Illuminate\Support\Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        // Angka ini cuma bukti sosial: data 15 menit lalu sama meyakinkannya dengan
        // data detik ini, jadi tidak perlu memukul database tiap halaman dibuka.
        $key = 'storefront:sold:' . md5($ids->sort()->implode(','));

        return \Illuminate\Support\Facades\Cache::remember($key, now()->addMinutes(15), function () use ($ids) {
            return \Illuminate\Support\Facades\DB::table('sales_invoice_items as i')
                ->join('sales_invoices as inv', 'inv.id', '=', 'i.sales_invoice_id')
                ->where('inv.status', 'posted')
                ->whereIn('i.product_id', $ids)
                ->groupBy('i.product_id')
                ->selectRaw('i.product_id, SUM(i.qty) as qty')
                ->pluck('qty', 'product_id')
                ->map(fn ($q) => (int) round((float) $q))
                ->all();
        });
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

    // ───────────────────────── Blog/Artikel ─────────────────────────

    /** Kategori artikel untuk navigasi blog. */
    public function articleCategories()
    {
        $cats = StoreArticleCategory::orderBy('sort_order')->orderBy('name')
            ->get(['id', 'slug', 'name', 'sort_order']);

        return response()->json(['data' => $cats]);
    }

    /**
     * Daftar artikel terbit (untuk listing blog + cache). Konten penuh TIDAK disertakan
     * (hemat payload) — ambil per-artikel via /articles/{slug}. ?updated_since & ?page.
     */
    public function articles(Request $request)
    {
        $query = StoreArticle::query()
            ->published()
            ->with('category')
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($since = $request->get('updated_since')) {
            $query->where('updated_at', '>=', $since);
        }
        if ($cat = $request->get('category')) {
            $query->whereHas('category', fn($q) => $q->where('slug', $cat));
        }

        $page = $query->paginate(min((int) $request->get('per_page', 30), 100))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn($a) => $this->serializeArticle($a, false))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    /** Detail satu artikel terbit by slug (dengan konten HTML). */
    public function article(string $slug)
    {
        $a = StoreArticle::published()->with('category')->where('slug', $slug)->first();

        if (!$a) {
            return response()->json(['message' => 'Artikel tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $this->serializeArticle($a, true)]);
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
        // Lookup media galeri per id → resolusi gambar per-varian (opsional).
        $mediaById = $p->media->keyBy('id');

        $variants = $p->variants->map(function ($v) use ($discounts, $mediaById) {
            // Dibulatkan 2 desimal di SATU tempat ini, bukan di tiap konsumen. Pengurangan
            // diskon dalam float biner menghasilkan ekor seperti 454999.08999999997, dan
            // angka itu ikut ke JSON-LD Schema.org (lowPrice) yang dibaca Google, ke kartu
            // produk, dan ke keranjang. Di layar tidak kelihatan karena diformat rupiah().
            $price = round((float) ($v->product->display_price ?? 0), 2);
            $disc  = $discounts[$v->product_id] ?? null;
            $final = $disc ? round(max(0, $price - (float) $disc['discount_amount']), 2) : $price;

            return [
                'id'             => $v->id,
                'sku'            => $v->product->sku ?? null,
                'product_id'     => $v->product_id,
                'label'          => $v->variant_label,
                'options'        => $v->option_values,
                'is_default'     => (bool) $v->is_default,
                'image_url'      => $v->image_media_id ? optional($mediaById->get($v->image_media_id))->url : null,
                'price'          => $final,
                'price_original' => $price,
                'discount'       => $disc ? [
                    'name'   => $disc['promotion_name'],
                    'amount' => round((float) $disc['discount_amount'], 2),
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
            'view_count'        => (int) $p->view_count,
            'sort_order'        => $p->sort_order,
            'category'          => $p->category ? [
                'id' => $p->category->id, 'slug' => $p->category->slug, 'name' => $p->category->name,
            ] : null,
            'has_variants'      => $p->hasVariants(),
            'variant_axes'      => $p->variant_axes,
            'media'             => $p->media->where('group', 'gallery')->map(fn($m) => [
                'kind'       => $m->kind,
                'source'     => $m->source,
                'url'        => $m->url,
                'alt'        => $m->alt_text,
                'is_primary' => (bool) $m->is_primary,
                'sort_order' => $m->sort_order,
            ])->values(),
            // Foto instansi/klien yang pernah memesan — bukti sosial di bawah deskripsi.
            'showcase'          => $p->media->where('group', 'showcase')
                ->sortBy('sort_order')
                ->map(fn($m) => [
                    'url'     => $m->url,
                    'caption' => $m->caption,
                    'alt'     => $m->alt_text,
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

    private function serializeArticle(StoreArticle $a, bool $withContent): array
    {
        return [
            'id'           => $a->id,
            'slug'         => $a->slug,
            'title'        => $a->title,
            'excerpt'      => $a->excerpt,
            'cover_url'    => $a->cover_url,
            'author'       => $a->author,
            'is_featured'  => (bool) $a->is_featured,
            'category'     => $a->category ? [
                'id' => $a->category->id, 'slug' => $a->category->slug, 'name' => $a->category->name,
            ] : null,
            'published_at' => optional($a->published_at)->toIso8601String(),
            'meta'         => [
                'title'       => $a->meta_title ?: $a->title,
                'description' => $a->meta_description ?: $a->excerpt,
            ],
            'updated_at'   => optional($a->updated_at)->toIso8601String(),
            // Konten HTML hanya di detail (hemat payload listing).
            'content'      => $withContent ? $a->content : null,
        ];
    }
}
