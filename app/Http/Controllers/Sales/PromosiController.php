<?php

namespace App\Http\Controllers\Sales;

use App\Core\Inventory\Product;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Promotion;
use App\Modules\Sales\Services\PromotionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromosiController extends Controller
{
    public const TYPES = [
        'item'       => 'Diskon Produk (per produk)',
        'shipping'   => 'Diskon Ongkir (bertingkat)',
        'cart_total' => 'Diskon Total Belanja',
    ];

    public function index(Request $request)
    {
        $query = Promotion::withCount(['tiers', 'products']);

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('voucher_code', 'like', "%$search%");
            });
        }

        if ($request->type && array_key_exists($request->type, self::TYPES)) {
            $query->where('type', $request->type);
        }

        if ($request->status === 'active') {
            $query->where('is_active', true);
        } elseif ($request->status === 'inactive') {
            $query->where('is_active', false);
        }

        $promos = $query->orderByDesc('id')->get();

        return view('erp.sales.promosi.index', [
            'promos' => $promos,
            'types'  => self::TYPES,
        ]);
    }

    public function create()
    {
        return view('erp.sales.promosi.create', [
            'promo'    => null,
            'types'    => self::TYPES,
            'products' => $this->productList(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        DB::transaction(function () use ($data, $request) {
            $promo = Promotion::create($this->masterAttributes($data));
            $this->syncChildren($promo, $data, $request);
        });

        return redirect()->route('sales.promosi.index')->with('success', 'Promosi berhasil dibuat.');
    }

    public function edit($id)
    {
        $promo = Promotion::with(['tiers', 'products'])->findOrFail($id);

        return view('erp.sales.promosi.edit', [
            'promo'    => $promo,
            'types'    => self::TYPES,
            'products' => $this->productList(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $promo = Promotion::findOrFail($id);
        $data = $this->validateData($request, $promo->id);

        DB::transaction(function () use ($promo, $data, $request) {
            $promo->update($this->masterAttributes($data));
            $promo->tiers()->delete();
            $promo->products()->delete();
            $this->syncChildren($promo, $data, $request);
        });

        return redirect()->route('sales.promosi.index')->with('success', 'Promosi berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $promo = Promotion::findOrFail($id);
        $promo->delete(); // cascade tiers & products

        return redirect()->route('sales.promosi.index')->with('success', 'Promosi dihapus.');
    }

    /** AJAX: hitung promo untuk konteks transaksi (Kasir/form). */
    public function resolveApi(Request $request)
    {
        $items = (array) $request->input('items', []);
        $items = array_map(fn ($it) => [
            'product_id' => (int) ($it['product_id'] ?? 0),
            'qty'        => (float) ($it['qty'] ?? 1),
            'unit_price' => (float) ($it['unit_price'] ?? 0),
        ], $items);

        $result = app(PromotionService::class)->resolve([
            'items'          => $items,
            'subtotal'       => (float) $request->input('subtotal', 0),
            'shipping_gross' => (float) $request->input('shipping_gross', 0),
            'voucher_code'   => $request->input('voucher_code'),
        ]);

        return response()->json($result);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function productList()
    {
        // Sertakan harga jual (display_price) untuk pratinjau harga sebelum/sesudah diskon di form.
        // Eager-load prices agar tidak N+1.
        return Product::with(['prices' => fn ($q) => $q->orderBy('id')])
            ->orderBy('name')
            ->get()
            ->map(function ($p) {
                $price = $p->sale_type === 'service'
                    ? ($p->base_price ?? 0)
                    : ($p->sale_type === 'non_stock'
                        ? ($p->cost_price ?? 0)
                        : ($p->prices->first()->price ?? 0));

                return [
                    'id'    => $p->id,
                    'sku'   => $p->sku,
                    'name'  => $p->name,
                    'price' => (float) $price,
                ];
            })
            ->values();
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $voucherRule = 'nullable|string|max:50';
        if ($request->boolean('is_voucher')) {
            $voucherRule = 'required|string|max:50|unique:promotions,voucher_code' . ($ignoreId ? ",$ignoreId" : '');
        }

        return $request->validate([
            'name'           => 'required|string|max:150',
            'type'           => 'required|in:item,shipping,cart_total',
            'discount_type'  => 'required|in:nominal,percent',
            'discount_value' => 'nullable',
            'applies_to_all' => 'nullable|boolean',
            'is_voucher'     => 'nullable|boolean',
            'voucher_code'   => $voucherRule,
            'is_active'      => 'nullable|boolean',
            'priority'       => 'nullable|integer',
            'starts_at'      => 'nullable|date',
            'ends_at'        => 'nullable|date|after_or_equal:starts_at',
            'notes'          => 'nullable|string|max:1000',
            'product_ids'    => 'nullable|array',
            'product_ids.*'  => 'integer|exists:products,id',
            'tiers'          => 'nullable|array',
            'tiers.*.min_spend'      => 'nullable',
            'tiers.*.discount_type'  => 'nullable|in:nominal,percent',
            'tiers.*.discount_value' => 'nullable',
        ]);
    }

    private function masterAttributes(array $data): array
    {
        return [
            'name'           => $data['name'],
            'type'           => $data['type'],
            'discount_type'  => $data['discount_type'],
            'discount_value' => $data['type'] === 'item' ? clean_number($data['discount_value'] ?? 0) : 0,
            'applies_to_all' => $data['type'] === 'item' ? (bool) ($data['applies_to_all'] ?? false) : false,
            'is_voucher'     => (bool) ($data['is_voucher'] ?? false),
            'voucher_code'   => ($data['is_voucher'] ?? false) ? ($data['voucher_code'] ?? null) : null,
            'is_active'      => (bool) ($data['is_active'] ?? false),
            'priority'       => (int) ($data['priority'] ?? 0),
            'starts_at'      => $data['starts_at'] ?? null,
            'ends_at'        => $data['ends_at'] ?? null,
            'notes'          => $data['notes'] ?? null,
        ];
    }

    private function syncChildren(Promotion $promo, array $data, Request $request): void
    {
        if ($promo->type === 'item') {
            if (!$promo->applies_to_all) {
                $ids = array_unique(array_filter($data['product_ids'] ?? []));
                foreach ($ids as $pid) {
                    $promo->products()->create(['product_id' => (int) $pid]);
                }
            }
            return;
        }

        // shipping & cart_total → tiers
        foreach (($data['tiers'] ?? []) as $tier) {
            $value = clean_number($tier['discount_value'] ?? 0);
            if ($value <= 0) {
                continue; // skip baris kosong
            }
            $promo->tiers()->create([
                'min_spend'      => clean_number($tier['min_spend'] ?? 0),
                'discount_type'  => in_array($tier['discount_type'] ?? 'nominal', ['nominal', 'percent'], true)
                    ? $tier['discount_type'] : 'nominal',
                'discount_value' => $value,
            ]);
        }
    }
}
