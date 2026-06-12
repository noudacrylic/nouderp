<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use Illuminate\Http\Request;
use App\Core\Inventory\ProductBundle;
use App\Core\Inventory\ProductUnit;
use Illuminate\Support\Facades\DB;
use App\Core\Inventory\StockLayer;
use App\Models\InventoryAccountSetting;
use App\Models\InventoryLedger;
use App\Models\InventoryCostLayer;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Accounting\Account;
use App\Core\Inventory\InventoryEngine;

class ProductController extends Controller
{
    /**
     * Kolom file Excel produk — dipakai bersama oleh Template (kosong), Export (berisi data),
     * dan dibaca balik oleh Import. Satu format saja supaya bisa round-trip (export → edit → import).
     * Sengaja TIDAK memuat Stok (→ Saldo Awal), Status, Last Cost, Lead Time, Dibuat (read-only).
     */
    private const IMPORT_HEADERS = [
        'SKU', 'Nama Produk', 'Tipe', 'Base Unit',
        'Base Price (Rp)', 'Cost Price (Rp)',
        'Berat (gram)', 'Panjang (cm)', 'Lebar (cm)', 'Tinggi (cm)',
        'Bisa Dijual',
    ];

    /**
     * Alias header yang diterima saat import (lowercase). Mendukung header ramah (Indonesia)
     * dari hasil Export maupun nama teknis lama, sehingga file lama tetap bisa di-upload.
     */
    private const IMPORT_ALIASES = [
        'sku'         => ['sku'],
        'name'        => ['nama produk', 'name', 'nama', 'product name'],
        'sale_type'   => ['tipe', 'sale_type', 'type', 'jenis'],
        'base_unit'   => ['base unit', 'base_unit', 'satuan', 'unit'],
        'base_price'  => ['base price (rp)', 'base_price', 'base price', 'harga jual', 'harga'],
        'cost_price'  => ['cost price (rp)', 'cost_price', 'cost price', 'harga pokok', 'hpp'],
        'weight_gram' => ['berat (gram)', 'weight_gram', 'berat gram', 'berat', 'weight'],
        'length_cm'   => ['panjang (cm)', 'length_cm', 'panjang', 'length', 'p (cm)'],
        'width_cm'    => ['lebar (cm)', 'width_cm', 'lebar', 'width', 'l (cm)'],
        'height_cm'   => ['tinggi (cm)', 'height_cm', 'tinggi', 'height', 't (cm)'],
        'is_sellable' => ['bisa dijual', 'is_sellable', 'dijual', 'sellable'],
    ];

    public function index(Request $request)
    {
        $query = Product::query();

        // Filter: Status
        $status = $request->status ?? 'active';
        if ($status == 'active') {
            $query->where('is_active', 1);
        } elseif ($status == 'archived') {
            $query->where('is_active', 0);
        }

        // Filter: Search
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('sku', 'like', '%' . $request->search . '%')
                    ->orWhere('name', 'like', '%' . $request->search . '%');
            });
        }

        // Filter: Type
        if ($request->type) {
            $query->where('sale_type', $request->type);
        }

        // Filter: Dijual (is_sellable)
        if ($request->sellable === 'yes') {
            $query->where('is_sellable', 1);
        } elseif ($request->sellable === 'no') {
            $query->where(fn($q) => $q->where('is_sellable', 0)->orWhereNull('is_sellable'));
        }

        $products = $query->orderBy('sale_type')
            ->orderBy('id', 'desc')
            ->get();

        $productIds = $products->pluck('id')->toArray();

        // Batch-load base prices (avoid N+1)
        $pricesMap = DB::table('product_prices')
            ->whereIn('product_id', $productIds)
            ->where('channel', 'default')
            ->get()
            ->keyBy(fn($r) => $r->product_id . '_' . $r->unit_name);

        // Batch-check which products are "used" (cannot be deleted, only archived)
        $usedIds = array_unique(array_merge(
            DB::table('inventory_ledgers')->whereIn('product_id', $productIds)->pluck('product_id')->toArray(),
            DB::table('sales_order_items')->whereIn('product_id', $productIds)->pluck('product_id')->toArray(),
            DB::table('bundle_components')->whereIn('component_product_id', $productIds)->pluck('component_product_id')->toArray(),
        ));

        foreach ($products as $product) {
            $key = $product->id . '_' . $product->base_unit;
            $product->base_price = isset($pricesMap[$key]) ? $pricesMap[$key]->price : 0;
            $product->is_used = in_array($product->id, $usedIds);
        }

        return view('erp.inventory.products.index', compact('products'));
    }

    public function updateInfo(Request $request, Product $product)
    {
        $rules = [];
        $data = [];

        if ($request->has('name')) {
            $rules['name'] = 'required';
            $data['name'] = $request->name;
        }

        if ($request->has('sku')) {
            $rules['sku'] = 'required|unique:products,sku,' . $product->id;
            $isUsed = DB::table('sales_order_items')
                ->where('product_id', $product->id)
                ->exists();
            if (!$isUsed) {
                $data['sku'] = $request->sku;
            }
        }

        $request->validate($rules);

        if ($request->has('base_unit')) {
            $data['base_unit'] = $request->base_unit;
        }

        if ($request->has('revenue_account_id')) {
            $data['revenue_account_id'] = $request->revenue_account_id;
        }

        if ($request->has('expense_account_id')) {
            $data['expense_account_id'] = $request->expense_account_id;
        }

        // Berat & dimensi untuk perhitungan ongkir (Fase 0 shipping)
        foreach (['weight_gram', 'length_cm', 'width_cm', 'height_cm'] as $f) {
            if ($request->has($f)) {
                $data[$f] = $request->filled($f) ? $request->input($f) : null;
            }
        }

        // Flag sinkron Jubelio (checkbox di form info; marker memastikan unchecked = false).
        if ($request->has('jubelio_section')) {
            $data['sync_to_jubelio'] = $request->boolean('sync_to_jubelio');
        }

        // Flag "Dijual" (muncul di form jual/POS). Marker memastikan unchecked = false.
        if ($request->has('sellable_section')) {
            $data['is_sellable'] = $request->boolean('is_sellable');
        }

        $product->update($data);

        return back()->with('success', 'Konfigurasi produk berhasil diperbarui.');
    }

    public function archive($id)
    {
        $product = Product::findOrFail($id);
        $product->update([
            'is_active' => 0
        ]);

        return redirect()
            ->back()
            ->with('success', 'Product archived');
    }

    public function ledger($id)
    {
        $product = Product::findOrFail($id);

        $ledgers = \App\Models\InventoryLedger::where('product_id', $id)
            ->with('warehouse')
            ->orderBy('id', 'asc')
            ->get();

        return view('erp.inventory.products.ledger', [
            'product' => $product,
            'ledger' => $ledgers
        ]);
    }

    public function restore(Product $product)
    {
        $product->update(['is_active' => 1]);
        return back()->with('success', 'Produk berhasil diaktifkan.');
    }

    public function updatePrice(Request $request)
    {
        $product = Product::findOrFail($request->product_id);

        if ($product->type === 'service') {
            $product->base_price = $request->price;
            $product->save();
        } elseif ($product->type === 'non_stock') {
            $product->cost_price = $request->price;
            $product->save();
        } else {
            \App\Models\ProductPrice::updateOrCreate(
                ['product_id' => $product->id, 'unit_name' => $product->base_unit ?? '', 'channel' => 'default'],
                ['price' => $request->price]
            );
        }

        return response()->json(['success' => true]);
    }

    /** Toggle flag "Dijual" (is_sellable) langsung dari halaman index produk. */
    public function updateSellable(Request $request)
    {
        $product = Product::findOrFail($request->product_id);
        $product->is_sellable = $request->boolean('is_sellable');
        $product->save();

        return response()->json(['success' => true, 'is_sellable' => (bool) $product->is_sellable]);
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        $used = \App\Models\InventoryLedger::where('product_id', $id)->exists();

        if ($used) {
            return redirect()
                ->back()
                ->with('error', 'Product cannot be deleted because it already has transactions');
        }

        $product->delete();

        return back()->with('success', 'Product deleted');
    }

    public function create()
    {
        return view('erp.inventory.products.create');
    }

    public function store(Request $request)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'type' => 'required'
        ];

        // Service & non_stock wajib pakai auto-SKU (di form, checkbox di-disable & SKU input di-disable
        // → keduanya tidak ikut ter-submit). Force di server agar tidak salah minta SKU manual.
        $forceAutoSku = in_array($request->input('type'), ['service', 'non_stock'], true);

        if (!$request->auto_sku && !$forceAutoSku) {
            $rules['sku'] = 'required|unique:products,sku';
        }

        $request->validate($rules);

        if ($request->auto_sku || $forceAutoSku) {
            $sku = $this->generateSku($request->type);
        } else {
            $sku = $request->sku;
        }

        $product = Product::create([
            'sku' => $sku,
            'name' => $request->name,
            'type' => $request->type,
            'unit' => 'pcs',
            'is_active' => 1
        ]);

        $routes = [
            'ready' => 'inventory.products.setup.ready',
            'preorder' => 'inventory.products.setup.preorder',
            'bundle' => 'inventory.products.setup.bundle',
            'service' => 'inventory.products.setup.service',
            'non_stock' => 'inventory.products.setup.non-stock',
        ];

        return redirect()->route($routes[$product->type] ?? 'inventory.products.setup', $product->id);
    }

    public function edit($id)
    {
        return redirect()->route('inventory.products.setup', $id);
    }

    public function setupReady(Product $product)
    {
        return $this->renderSetup($product, 'setup_ready');
    }

    public function setupPreorder(Product $product)
    {
        return $this->renderSetup($product, 'setup_preorder');
    }

    public function setupBundle(Product $product)
    {
        return $this->renderSetup($product, 'setup_bundle');
    }

    public function setupService(Product $product)
    {
        return $this->renderSetup($product, 'setup_service');
    }

    public function setupNonStock(Product $product)
    {
        return $this->renderSetup($product, 'setup_non_stock');
    }


    private function renderSetup(Product $product, $viewName)
    {
        if ($product->type === 'service') {
            $accounts = \App\Core\Accounting\Account::where('type', 'revenue')->orderBy('code')->get();
        } elseif ($product->type === 'non_stock') {
            $accounts = \App\Core\Accounting\Account::where('type', 'expense')->orderBy('code')->get();
        } else {
            $accounts = \App\Core\Accounting\Account::orderBy('code')->get();
        }

        $warehouses = Warehouse::all();

        $opening = \App\Core\Inventory\StockLayer::where('product_id', $product->id)
            ->where('source_type', 'OPENING')
            ->first();

        $openingQty = $opening ? $opening->qty_in : 0;
        $openingCost = $opening ? $opening->unit_cost : 0;
        $openingWarehouseId = $opening ? $opening->warehouse_id : null;

        $units = DB::table('product_units')
            ->where('product_id', $product->id)
            ->orderBy('level')
            ->get();

        $prices = DB::table('product_prices')
            ->where('product_id', $product->id)
            ->get();

        $costs = DB::table('product_costs')
            ->where('product_id', $product->id)
            ->get();

        $components = $product->bundleComponents()->with('component')->get();
        $allProducts = Product::where('sale_type', '!=', 'bundle')->get();

        return view('erp.inventory.products.' . $viewName, compact(
            'product',
            'accounts',
            'warehouses',
            'units',
            'prices',
            'costs',
            'components',
            'opening',
            'openingQty',
            'openingCost',
            'openingWarehouseId',
            'allProducts'
        ));
    }

    public function setup(Product $product)
    {
        // Fallback for legacy generic route
        $viewMap = [
            'ready' => 'setup_ready',
            'preorder' => 'setup_preorder',
            'bundle' => 'setup_bundle',
            'service' => 'setup_service',
            'non_stock' => 'setup_non_stock'
        ];
        return $this->renderSetup($product, $viewMap[$product->type] ?? 'setup');
    }

    public function storeUnits(Request $request, Product $product)
    {
        $request->validate([
            'base_unit' => 'required',
            'units.*.name' => 'nullable|string',
            'units.*.conversion' => 'nullable|numeric|min:0.0001'
        ]);

        DB::transaction(function () use ($request, $product) {
            // 1. Update base_unit in main table
            $product->update(['base_unit' => $request->base_unit]);

            // 2. Clear existing units
            \App\Core\Inventory\ProductUnit::where('product_id', $product->id)->delete();

            // 3. Create level 0 unit (base unit)
            \App\Core\Inventory\ProductUnit::create([
                'product_id' => $product->id,
                'unit_name' => $request->base_unit,
                'conversion_to_base' => 1,
                'level' => 0
            ]);

            // 4. Create additional units
            if ($request->units) {
                foreach ($request->units as $i => $u) {
                    if (empty($u['name']))
                        continue;

                    \App\Core\Inventory\ProductUnit::create([
                        'product_id' => $product->id,
                        'unit_name' => $u['name'],
                        'conversion_to_base' => $u['conversion'],
                        'level' => $i + 1
                    ]);
                }
            }

            // 5. Ensure stocks exist
            $warehouses = \App\Core\Inventory\Warehouse::all();
            foreach ($warehouses as $wh) {
                \App\Core\Inventory\ProductStock::firstOrCreate([
                    'product_id' => $product->id,
                    'warehouse_id' => $wh->id
                ]);
            }
        });

        return back()->with('success', 'Units updated.');
    }

    public function storePrice(Request $request, Product $product)
    {
        if (in_array($product->type, ['service', 'non_stock'])) {
            $request->validate(['price' => 'required|numeric|min:0']);
            $product->base_price = $request->price;
            $product->save();

            return back()->with('success', 'Harga disimpan');
        }

        $request->validate([
            'unit_name' => 'required',
            'price' => 'required|numeric|min:0'
        ]);

        DB::table('product_prices')->updateOrInsert(
            [
                'product_id' => $product->id,
                'unit_name' => $request->unit_name,
                'channel' => 'default'
            ],
            [
                'price' => $request->price,
                'updated_at' => now(),
                'created_at' => now()
            ]
        );

        return back()->with('success', 'Price saved successfully');
    }

    public function saveCost(Request $request, Product $product)
    {
        if (in_array($product->type, ['service', 'non_stock'])) {
            $request->validate(['cost_price' => 'required|numeric|min:0']);
            $product->cost_price = $request->cost_price;
            $product->save();
            return back()->with('success', 'Harga beli disimpan');
        }

        $request->validate([
            'unit_name' => 'required',
            'cost' => 'required|numeric|min:0'
        ]);

        DB::table('product_costs')->updateOrInsert(
            [
                'product_id' => $product->id,
                'unit_name' => $request->unit_name
            ],
            [
                'cost' => $request->cost,
                'updated_at' => now(),
                'created_at' => now()
            ]
        );

        return back()->with('success', 'Cost updated');
    }

    public function savePreorderConfig(Request $request, Product $product)
    {
        $request->validate([
            'preorder_stock' => 'required|integer|min:0',
            'lead_time_days' => 'required|integer|min:0'
        ]);

        $product->update([
            'preorder_stock' => $request->preorder_stock,
            'lead_time_days' => $request->lead_time_days
        ]);

        return back()->with('success', 'Preorder configuration saved successfully.');
    }

    public function saveBundle(Request $request, Product $product)
    {
        \App\Core\Inventory\BundleComponent::where('bundle_product_id', $product->id)->delete();

        if ($request->components) {
            foreach ($request->components as $c) {
                if (empty($c['product_id']))
                    continue;

                \App\Core\Inventory\BundleComponent::create([
                    'bundle_product_id' => $product->id,
                    'component_product_id' => $c['product_id'],
                    'qty' => $c['qty'] ?? 1
                ]);
            }
        }

        return back()->with('success', 'Bundle updated successfully.');
    }

    public function saveServiceConfig(Request $request, Product $product)
    {
        $request->validate([
            'base_unit' => 'required',
            'income_account_id' => 'required|exists:accounts,id'
        ]);

        $product->update([
            'base_unit' => $request->base_unit,
            'income_account_id' => $request->income_account_id
        ]);

        // Sync to product_units table for consistency
        DB::table('product_units')->updateOrInsert(
            ['product_id' => $product->id, 'level' => 0],
            [
                'unit_name' => $request->base_unit,
                'conversion_to_base' => 1,
                'is_active' => 1,
                'updated_at' => now(),
                'created_at' => now()
            ]
        );

        return back()->with('success', 'Service configuration saved.');
    }

    public function saveNonStockConfig(Request $request, Product $product)
    {
        $request->validate([
            'base_unit' => 'required',
            'expense_account_id' => 'required|exists:accounts,id'
        ]);

        $product->update([
            'base_unit' => $request->base_unit,
            'expense_account_id' => $request->expense_account_id
        ]);

        // Sync to product_units table for consistency
        DB::table('product_units')->updateOrInsert(
            ['product_id' => $product->id, 'level' => 0],
            [
                'unit_name' => $request->base_unit,
                'conversion_to_base' => 1,
                'is_active' => 1,
                'updated_at' => now(),
                'created_at' => now()
            ]
        );

        return back()->with('success', 'Non-stock configuration saved.');
    }

    public function units($id)
    {
        $product = \App\Core\Inventory\Product::findOrFail($id);
        $units = \App\Core\Inventory\ProductUnit::where('product_id', $id)->get();
        $prices = \App\Models\ProductPrice::where('product_id', $id)->get();
        $costs = \App\Models\ProductCost::where('product_id', $id)->get(['unit_name', 'cost']);

        return response()->json([
            'base_unit' => $product->base_unit,
            'cost_price' => (float) ($product->cost_price ?? 0),
            'last_cost' => (float) ($product->last_cost ?? 0),
            'units' => $units,
            'prices' => $prices,
            'costs' => $costs, // [{unit_name, cost}, ...] — harga pembelian per satuan
        ]);
    }

    public function prices($id)
    {
        $prices = \App\Models\ProductPrice::where('product_id', $id)->get();
        return response()->json($prices);
    }

    public function search(Request $request)
    {
        $q = $request->q;

        $products = \App\Core\Inventory\Product::query()
            ->with(['units' => fn($u) => $u->where('is_active', true)->orderBy('level')])
            // Konteks produksi (BOM): produk bundle tidak masuk produksi.
            ->when($request->boolean('exclude_bundle'), fn($qq) => $qq->where('sale_type', '!=', 'bundle'))
            // Konteks Produk Sampingan: hanya barang jadi berstok (ready stok).
            ->when($request->boolean('ready_only'), fn($qq) => $qq->where('sale_type', 'ready'))
            // Konteks JUAL (Penawaran/SO/Faktur): hanya produk yang ditandai "Dijual".
            // Produksi/purchasing/warranty TIDAK kirim param ini → semua produk tampil.
            ->when($request->boolean('sellable_only'), function ($qq) {
                $qq->where('is_sellable', true)
                    // Guard: bundle TANPA komponen tidak boleh masuk transaksi jual —
                    // fulfillment & HPP-nya gagal karena tidak ada komponen untuk dikonsumsi.
                    // Komponen bisa di tabel bundle_components (utama) atau product_bundles (fallback).
                    ->where(function ($w) {
                        $w->where('sale_type', '!=', 'bundle')
                          ->orWhereExists(fn($s) => $s->selectRaw('1')->from('bundle_components')
                                ->whereColumn('bundle_components.bundle_product_id', 'products.id'))
                          ->orWhereExists(fn($s) => $s->selectRaw('1')->from('product_bundles')
                                ->whereColumn('product_bundles.bundle_product_id', 'products.id'));
                    });
            })
            ->where(function ($w) use ($q) {
                $w->where('sku', 'like', "%$q%")
                  ->orWhere('name', 'like', "%$q%");
            })
            ->limit(10)
            ->get();

        $results = $products->map(function($product) {
            // Daftar satuan: base_unit (utama) + satuan konversi aktif, tanpa duplikat.
            $units = collect();
            if ($product->base_unit) $units->push($product->base_unit);
            foreach ($product->units as $u) {
                if ($u->unit_name && !$units->contains($u->unit_name)) $units->push($u->unit_name);
            }

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'sale_type' => $product->sale_type,
                'base_unit' => $product->base_unit,
                'units' => $units->values()->all(),
                'price' => $product->display_price ?? 0,
                // Untuk konteks pembelian: harga pembelian referensi yang user simpan di produk.
                // last_cost lebih up-to-date (auto dari invoice posting), cost_price = manual reference.
                'cost_price' => (float) ($product->cost_price ?? 0),
                'last_cost' => (float) ($product->last_cost ?? 0),
            ];
        });

        return response()->json($results);
    }

    public function stock(Request $request, $productId)
    {
        $warehouseId  = $request->get('warehouse_id') ?: null;
        $salesOrderId = $request->get('sales_order_id') ?: null;

        // Sumber kebenaran stok = product_stocks (sejalan dengan halaman Inventory > Stocks).
        if ($warehouseId) {
            $balance = (float) ProductStock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->value('qty_on_hand') ?? 0;
            $sellableBalance = $balance;
        } else {
            // Agregat semua warehouse (qty_on_hand) untuk informasi fisik total.
            $balance = (float) ProductStock::where('product_id', $productId)->sum('qty_on_hand');
            // "Tersedia" hanya dihitung dari warehouse yang sellable.
            $sellableBalance = (float) ProductStock::where('product_id', $productId)
                ->whereHas('warehouse', fn($q) => $q->where('is_sellable', true))
                ->sum('qty_on_hand');
        }

        // Reservasi aktif — kecualikan reservasi milik SO yang sedang di-invoice.
        $resQuery = \App\Core\Inventory\StockReservation::where('product_id', $productId)
            ->where('status', 'active');
        if ($warehouseId)  $resQuery->where('warehouse_id', $warehouseId);
        if ($salesOrderId) $resQuery->where('sales_order_id', '!=', $salesOrderId);
        $reservedOthers = (float) $resQuery->sum('qty');

        $preorderStock = (float) (Product::where('id', $productId)->value('preorder_stock') ?? 0);

        $available = max(0, $sellableBalance - $reservedOthers + $preorderStock);

        return response()->json([
            'stock'               => $available,
            'qty_on_hand'         => $balance,
            'qty_reserved_others' => $reservedOthers,
            'sales_order_id'      => $salesOrderId ? (int) $salesOrderId : null,
            'warehouse_id'        => $warehouseId ? (int) $warehouseId : null,
        ]);
    }

    public function components($id)
    {
        $components = ProductBundle::where('bundle_product_id', $id)
            ->with(['component'])
            ->get();

        $engine = app(InventoryEngine::class);

        $results = $components->map(function ($c) use ($engine) {
            return [
                'id' => $c->component_product_id,
                'sku' => $c->component->sku,
                'name' => $c->component->name,
                'qty_required' => (float)$c->qty_required,
                'available_stock' => (float)$engine->availableStock($c->component_product_id)
            ];
        });

        return response()->json($results);
    }

    public function finishSetup(Request $request, Product $product)
    {
        // 1. Activate Product
        if (!$product->is_active) {
            $product->update(['is_active' => 1]);
        }

        return redirect(list_url('inventory.products.index'))->with('success', 'Produk berhasil diaktifkan.');
    }

    public function postOpeningBalance(Request $request, $product_id)
    {
        $product = Product::findOrFail($product_id);

        $qty = $request->opening_qty;
        $cost = $request->opening_cost;
        $warehouse_id = $request->opening_warehouse_id; // Using blade field name

        $value = $qty * $cost;

        $setting = InventoryAccountSetting::first();

        DB::transaction(function () use ($product, $qty, $cost, $value, $warehouse_id, $setting) {

            $product->last_cost = $cost;
            $product->save();

            // USE CENTRALIZED ENGINE
            $engine = app(\App\Core\Inventory\InventoryEngine::class);
            
            $exists = \App\Models\InventoryLedger::where([
                'product_id' => $product->id,
                'transaction_type' => 'opening',
                'transaction_id' => $product->id
            ])->exists();

            if ($exists) {
                $engine->updateOpening($product->id, $warehouse_id, (float)$qty, (float)$cost, $product->id);
            } else {
                $engine->opening($product->id, $warehouse_id, (float)$qty, (float)$cost, $product->id);
            }

            $period = AccountingPeriod::where('status', 'open')
                ->where('start_date', '<=', now())
                ->where('end_date', '>=', now())
                ->first();

            if (!$period) {
                // Fallback: search by year/month if exact date range is weird
                $period = AccountingPeriod::where('status', 'open')
                    ->where('year', now()->year)
                    ->where('month', now()->month)
                    ->first();
            }

            if (!$period) {
                throw new \Exception("Periode akuntansi aktif tidak ditemukan untuk bulan ini. Harap buka periode di menu Accounting -> Period.");
            }

            // FIND OR CREATE JOURNAL
            $journal = Journal::where([
                'reference_type' => 'product',
                'reference_id' => $product->id,
            ])->where('journal_number', 'like', 'OB-%')->first();

            if ($journal) {
                $journal->update([
                    'period_id' => $period->id,
                    'date' => now(),
                ]);
                // Clear old lines
                JournalLine::where('journal_id', $journal->id)->delete();
            } else {
                $journal = Journal::create([
                    'journal_number' => 'OB-' . uniqid(),
                    'date' => now(),
                    'period_id' => $period->id,
                    'reference_type' => 'product',
                    'reference_id' => $product->id,
                    'description' => 'Opening balance product ' . $product->sku,
                    'status' => 'posted'
                ]);
            }

            $inventoryAccount = Account::findOrFail($setting->inventory_asset_account_id);
            $openingAccount = Account::findOrFail($setting->opening_balance_account_id);

            JournalLine::create([
                'journal_id' => $journal->id,
                'account_id' => $inventoryAccount->id,
                'debit' => $value,
                'credit' => 0
            ]);

            JournalLine::create([
                'journal_id' => $journal->id,
                'account_id' => $openingAccount->id,
                'debit' => 0,
                'credit' => $value
            ]);

        });

        return back()->with('success', 'Opening balance posted');
    }



    /**
     * Form upload Excel bulk produk.
     */
    public function bulkImport()
    {
        return view('erp.inventory.products.bulk-import');
    }

    /**
     * Download template Excel untuk bulk import.
     */
    public function bulkImportTemplate()
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Produk');

        // Header — SAMA PERSIS dengan kolom hasil Export, supaya bisa round-trip
        // (export → edit → import lagi). Hanya kolom yang BISA diisi saat buat produk.
        // Stok & Status TIDAK ada di sini: stok → Saldo Awal, status (arsip) → halaman edit.
        $sheet->fromArray(self::IMPORT_HEADERS, null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FEF3C7');

        // Contoh — baris diawali "CONTOH" supaya gampang dihapus, jelas ini bukan data riil.
        $sheet->fromArray([
            ['CONTOH-001', 'Contoh Produk (hapus baris ini)', 'ready',    'pcs', 50000,  30000,  500, 20,  15,  5,  'Ya'],
            ['',           'Auto-SKU jika kolom SKU kosong',   'ready',    'pcs', 100000, 60000,  250, '',  '',  '', 'Ya'],
            ['CONTOH-SRV', 'Contoh Jasa',                      'service',  'pcs', 25000,  0,      '',  '',  '',  '', 'Ya'],
            ['CONTOH-CMP', 'Contoh Komponen (tidak dijual)',   'ready',    'pcs', 0,      15000,  300, 10,  10,  3,  'Tidak'],
        ], null, 'A2');

        // Width
        foreach (['A' => 18, 'B' => 42, 'C' => 12, 'D' => 12, 'E' => 16, 'F' => 16,
                  'G' => 12, 'H' => 12, 'I' => 12, 'J' => 12, 'K' => 12] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->getStyle('E:J')->getNumberFormat()->setFormatCode('#,##0');

        // Petunjuk
        $sheet->setCellValue('A8', 'Petunjuk:');
        $sheet->setCellValue('A9',  '- SKU: kosongkan untuk auto-generate (PRD00xxx, dst sesuai Tipe). SKU yang sudah ada akan di-skip.');
        $sheet->setCellValue('A10', '- Nama Produk: WAJIB diisi.');
        $sheet->setCellValue('A11', '- Tipe: ready / preorder / bundle / service / non_stock. Default = ready.');
        $sheet->setCellValue('A12', '- Base Unit: satuan dasar, default "pcs".');
        $sheet->setCellValue('A13', '- Base Price (Rp): harga jual. Boleh kosong (default 0).');
        $sheet->setCellValue('A14', '- Cost Price (Rp): harga pokok / referensi pembelian. Boleh kosong.');
        $sheet->setCellValue('A15', '- Berat (gram): berat satuan dalam GRAM (untuk ongkir). Boleh kosong.');
        $sheet->setCellValue('A16', '- Panjang / Lebar / Tinggi (cm): dimensi satuan untuk ongkir volumetrik. Opsional, boleh desimal.');
        $sheet->setCellValue('A17', '- Bisa Dijual: Ya = muncul di Kasir/Penawaran/Faktur. Tidak = komponen/setengah jadi. Default = Ya.');
        $sheet->setCellValue('A18', '- Hapus baris CONTOH di atas sebelum isi data riil.');
        $sheet->setCellValue('A19', '- STOK AWAL diisi terpisah di menu Saldo Awal (bukan di file ini).');
        $sheet->setCellValue('A20', '- Produk baru otomatis berstatus aktif. Untuk arsip, pakai halaman edit per produk.');
        $sheet->getStyle('A8')->getFont()->setBold(true);
        $sheet->getStyle('A9:A20')->getFont()->setItalic(true);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'template-import-produk.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Export produk ke Excel — respect filter index (search/type/status).
     * Tujuan: analisa offline (price list, stok, kategori, dll).
     */
    public function export(Request $request)
    {
        $query = Product::query();

        $status = $request->status ?? 'active';
        if ($status === 'active') {
            $query->where('is_active', 1);
        } elseif ($status === 'archived') {
            $query->where('is_active', 0);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('sku', 'like', '%' . $request->search . '%')
                    ->orWhere('name', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->type) {
            $query->where('sale_type', $request->type);
        }

        $products = $query->orderBy('sale_type')->orderBy('id', 'desc')->get();
        $productIds = $products->pluck('id')->toArray();

        // Batch-load base prices (default channel)
        $pricesMap = DB::table('product_prices')
            ->whereIn('product_id', $productIds)
            ->where('channel', 'default')
            ->get()
            ->keyBy(fn($r) => $r->product_id . '_' . $r->unit_name);

        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Produk');

        // Header SAMA dengan template import → file ini bisa diedit lalu di-upload balik.
        // Stok TIDAK diekspor di sini (lihat menu Saldo Awal); Status diatur via halaman edit.
        $sheet->fromArray(self::IMPORT_HEADERS, null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FEF3C7');

        $row = 2;
        foreach ($products as $p) {
            $key = $p->id . '_' . $p->base_unit;
            $basePrice = isset($pricesMap[$key]) ? (float) $pricesMap[$key]->price : (float) ($p->base_price ?? 0);

            $sheet->setCellValue("A{$row}", $p->sku);
            $sheet->setCellValue("B{$row}", $p->name);
            $sheet->setCellValue("C{$row}", $p->sale_type);
            $sheet->setCellValue("D{$row}", $p->base_unit ?? '');
            $sheet->setCellValue("E{$row}", $basePrice);
            $sheet->setCellValue("F{$row}", (float) ($p->cost_price ?? 0));
            $sheet->setCellValue("G{$row}", $p->weight_gram !== null ? (int) $p->weight_gram : '');
            $sheet->setCellValue("H{$row}", $p->length_cm !== null ? (float) $p->length_cm : '');
            $sheet->setCellValue("I{$row}", $p->width_cm !== null ? (float) $p->width_cm : '');
            $sheet->setCellValue("J{$row}", $p->height_cm !== null ? (float) $p->height_cm : '');
            $sheet->setCellValue("K{$row}", $p->is_sellable ? 'Ya' : 'Tidak');
            $row++;
        }

        // Column widths
        foreach (['A' => 18, 'B' => 45, 'C' => 12, 'D' => 12, 'E' => 16, 'F' => 16,
                  'G' => 12, 'H' => 12, 'I' => 12, 'J' => 12, 'K' => 12] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // Format kolom angka (Rp / gram / cm) dengan separator titik
        $lastRow = max($row - 1, 2);
        $sheet->getStyle("E2:J{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');

        $filename = 'produk-' . now()->format('Ymd-His') . '.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Parse Excel & bulk-create produk. Skip baris yang SKU-nya sudah ada (tanpa error).
     * Per produk: bikin row di products + product_units (level 0) + product_prices (default channel).
     */
    public function bulkImportStore(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);

        try {
            $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath());
            $rows = $ss->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal baca file: ' . $e->getMessage());
        }

        if (count($rows) < 2) {
            return back()->with('error', 'File kosong atau cuma header (perlu minimal 1 baris data).');
        }

        // Header normalize — terima header ramah (hasil Export, mis. "Nama Produk") maupun
        // nama teknis lama (mis. "name"). Kolom yang tak dikenal (Stok/Status/dll) diabaikan.
        $header = array_map(fn($v) => strtolower(trim((string) $v)), $rows[0]);
        $idx = [];
        foreach (self::IMPORT_ALIASES as $field => $aliases) {
            $idx[$field] = false;
            foreach ($aliases as $alias) {
                $found = array_search($alias, $header, true);
                if ($found !== false) {
                    $idx[$field] = $found;
                    break;
                }
            }
        }
        if ($idx['name'] === false) {
            return back()->with('error', 'Kolom "Nama Produk" wajib ada di file Excel.');
        }

        $validTypes = ['ready', 'preorder', 'bundle', 'service', 'non_stock'];
        $created = 0;
        $skipped = [];
        $errors  = [];

        DB::transaction(function () use ($rows, $idx, $validTypes, &$created, &$skipped, &$errors) {
            for ($i = 1; $i < count($rows); $i++) {
                $r = $rows[$i];
                $rowNum = $i + 1;

                $name = trim((string) ($r[$idx['name']] ?? ''));
                if ($name === '') continue;  // skip baris kosong

                $sku = $idx['sku'] !== false ? trim((string) ($r[$idx['sku']] ?? '')) : '';
                $saleType = $idx['sale_type'] !== false ? strtolower(trim((string) ($r[$idx['sale_type']] ?? ''))) : 'ready';
                if (!in_array($saleType, $validTypes, true)) $saleType = 'ready';

                $baseUnit = $idx['base_unit'] !== false ? trim((string) ($r[$idx['base_unit']] ?? '')) : '';
                if ($baseUnit === '') $baseUnit = 'pcs';

                $basePrice = $idx['base_price'] !== false ? (float) clean_number($r[$idx['base_price']] ?? 0) : 0;
                $costPrice = $idx['cost_price'] !== false ? (float) clean_number($r[$idx['cost_price']] ?? 0) : 0;

                // Berat satuan (gram) — pakai clean_number (handle "1.000" = 1000).
                $weightGram = $idx['weight_gram'] !== false && trim((string) ($r[$idx['weight_gram']] ?? '')) !== ''
                    ? (int) round((float) clean_number($r[$idx['weight_gram']]))
                    : null;

                // Dimensi satuan (cm) untuk ongkir volumetrik — opsional, boleh desimal.
                $readDim = function ($field) use ($idx, $r) {
                    if ($idx[$field] === false) return null;
                    $raw = trim((string) ($r[$idx[$field]] ?? ''));
                    return $raw !== '' ? (float) clean_number($raw) : null;
                };
                $lengthCm = $readDim('length_cm');
                $widthCm  = $readDim('width_cm');
                $heightCm = $readDim('height_cm');

                // Bisa dijual — default TRUE. Terima: kosong/ya/yes/y/true/1/dijual = true; tidak/no/n/false/0 = false.
                $isSellable = true;
                if ($idx['is_sellable'] !== false) {
                    $sv = strtolower(trim((string) ($r[$idx['is_sellable']] ?? '')));
                    if ($sv !== '') {
                        $isSellable = !in_array($sv, ['tidak', 'no', 'n', 'false', '0', 'non', 'nonsell', 'tdk'], true);
                    }
                }

                // Auto-SKU kalau kosong
                if ($sku === '') {
                    $sku = $this->generateSku($saleType);
                } else {
                    if (Product::where('sku', $sku)->exists()) {
                        $skipped[] = "Row $rowNum: SKU '$sku' sudah ada";
                        continue;
                    }
                }

                try {
                    $product = Product::create([
                        'sku'         => $sku,
                        'name'        => $name,
                        'sale_type'   => $saleType,
                        'base_unit'   => $baseUnit,
                        'base_price'  => $basePrice,
                        'cost_price'  => $costPrice,
                        'weight_gram' => $weightGram,
                        'length_cm'   => $lengthCm,
                        'width_cm'    => $widthCm,
                        'height_cm'   => $heightCm,
                        'is_sellable' => $isSellable,
                        'is_active'   => 1,
                    ]);

                    // Bikin level-0 unit
                    DB::table('product_units')->insert([
                        'product_id'         => $product->id,
                        'unit_name'          => $baseUnit,
                        'conversion_to_base' => 1,
                        'level'              => 0,
                        'is_active'          => 1,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);

                    // Bikin harga default channel kalau base_price > 0
                    if ($basePrice > 0) {
                        DB::table('product_prices')->insert([
                            'product_id' => $product->id,
                            'unit_name'  => $baseUnit,
                            'channel'    => 'default',
                            'price'      => $basePrice,
                            'is_active'  => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $created++;
                } catch (\Throwable $e) {
                    $errors[] = "Row $rowNum ($name): " . $e->getMessage();
                }
            }
        });

        $msg = "✓ $created produk dibuat.";
        if (!empty($skipped)) $msg .= ' Skip: ' . count($skipped) . ' baris (SKU duplikat).';
        if (!empty($errors))  $msg .= ' Error: ' . count($errors) . ' baris.';

        return redirect(list_url('inventory.products.index'))
            ->with('success', $msg)
            ->with('import_skipped', $skipped)
            ->with('import_errors', $errors);
    }

    private function generateSku($type)
    {
        $prefix = match ($type) {
            'ready' => 'PRD',
            'preorder' => 'PRE',
            'bundle' => 'BND',
            'service' => 'SRV',
            'non_stock' => 'NST',
            default => 'PRD'
        };

        $last = Product::where('sku', 'like', $prefix . '%')
            ->orderBy('id', 'desc')
            ->first();

        $number = 1;

        if ($last) {
            $lastNumber = intval(substr($last->sku, strlen($prefix)));
            $number = $lastNumber + 1;
        }

        return $prefix . str_pad($number, 5, '0', STR_PAD_LEFT);
    }
}
