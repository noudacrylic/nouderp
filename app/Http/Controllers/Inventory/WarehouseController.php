<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Core\Inventory\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->input('q', $request->input('search', '')));

        $warehouses = Warehouse::where('is_active', 1)
            ->withSum('stocks as stock_total', 'qty_on_hand')
            ->withCount([
                'stocks as has_stock_count' => fn($s) => $s->where('qty_on_hand', '>', 0),
            ])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('location', 'like', "%{$q}%");
                });
            })
            ->orderBy('id', 'desc')
            ->paginate(20)
            ->withQueryString();

        // Tandai apakah warehouse "sudah dipakai" (ada stock, ledger, atau aset).
        // is_used = TRUE → sembunyikan tombol Hapus, tampilkan Archive saja.
        $ids = $warehouses->pluck('id')->all();
        $hasLedgerIds = \App\Models\InventoryLedger::whereIn('warehouse_id', $ids)
            ->distinct()->pluck('warehouse_id')->all();
        $hasAssetIds = \App\Modules\FixedAsset\Models\FixedAsset::whereIn('warehouse_id', $ids)
            ->whereNotIn('status', ['voided'])
            ->distinct()->pluck('warehouse_id')->all();

        $warehouses->getCollection()->transform(function ($w) use ($hasLedgerIds, $hasAssetIds) {
            $w->is_used = ($w->has_stock_count > 0)
                || in_array($w->id, $hasLedgerIds)
                || in_array($w->id, $hasAssetIds);
            return $w;
        });

        return view('erp.inventory.warehouses.index', compact('warehouses'));
    }

    public function show($id)
    {
        $warehouse = Warehouse::findOrFail($id);

        // Stock produk saat ini (qty > 0). Pakai eager-load product + cost-layer terakhir untuk valuation.
        $stocks = \App\Core\Inventory\ProductStock::with('product')
            ->where('warehouse_id', $id)
            ->where('qty_on_hand', '>', 0)
            ->get()
            ->map(function ($s) {
                $unitCost = (float) optional(
                    \App\Core\Inventory\StockLayer::where('product_id', $s->product_id)
                        ->where('warehouse_id', $s->warehouse_id)
                        ->where('qty_remaining', '>', 0)
                        ->orderByDesc('id')
                        ->first()
                )->unit_cost ?? 0;
                $s->unit_cost = $unitCost;
                $s->stock_value = round($s->qty_on_hand * $unitCost, 2);
                return $s;
            })
            ->sortBy(fn($s) => $s->product->name ?? '')
            ->values();

        $assets = \App\Modules\FixedAsset\Models\FixedAsset::with('category')
            ->where('warehouse_id', $id)
            ->whereNotIn('status', ['voided'])
            ->orderBy('asset_code')
            ->get();

        return view('erp.inventory.warehouses.show', [
            'warehouse' => $warehouse,
            'stocks' => $stocks,
            'assets' => $assets,
            'totalStockValue' => $stocks->sum('stock_value'),
            'totalAssetValue' => (float) $assets->sum('current_book_value'),
        ]);
    }

    public function ledger($id)
    {
        $warehouse = Warehouse::findOrFail($id);

        $ledgers = \App\Models\InventoryLedger::where('warehouse_id', $id)
            ->with('product')
            ->orderBy('id', 'asc')
            ->get();

        $balance = 0;
        $ledgers->transform(function ($row) use (&$balance) {
            $balance += $row->qty_in;
            $balance -= $row->qty_out;
            $row->balance = $balance;
            return $row;
        });

        $ledgers = $ledgers->reverse();

        return view('erp.inventory.warehouses.ledger', [
            'warehouse' => $warehouse,
            'ledger' => $ledgers
        ]);
    }

    public function create()
    {
        return view('erp.inventory.warehouses.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'location'         => 'nullable|string|max:255',
            'address'          => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:255',
            'province'         => 'nullable|string|max:255',
            'postal_code'      => 'nullable|string|max:10',
            'biteship_area_id' => 'nullable|string|max:255',
            'kiriminaja_area_id' => 'nullable|string|max:255',
            'contact_name'     => 'nullable|string|max:255',
            'contact_phone'    => 'nullable|string|max:50',
            'location_point'   => 'nullable|string|max:500',
        ]);

        $point = parse_lat_long($data['location_point'] ?? null);
        unset($data['location_point']);
        $data['latitude']  = $point['latitude'];
        $data['longitude'] = $point['longitude'];

        Warehouse::create($data + [
            'is_sellable' => $request->has('is_sellable'),
            'is_active'   => true,
        ]);

        return redirect(list_url('inventory.warehouses.index'))
            ->with('success', 'Warehouse created');
    }
    public function edit($id)
    {
        $warehouse = Warehouse::findOrFail($id);
        return view('erp.inventory.warehouses.edit', compact('warehouse'));
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'location'         => 'nullable|string|max:255',
            'address'          => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:255',
            'province'         => 'nullable|string|max:255',
            'postal_code'      => 'nullable|string|max:10',
            'biteship_area_id' => 'nullable|string|max:255',
            'kiriminaja_area_id' => 'nullable|string|max:255',
            'contact_name'     => 'nullable|string|max:255',
            'contact_phone'    => 'nullable|string|max:50',
            'location_point'   => 'nullable|string|max:500',
        ]);

        $point = parse_lat_long($data['location_point'] ?? null);
        unset($data['location_point']);
        $data['latitude']  = $point['latitude'];
        $data['longitude'] = $point['longitude'];

        $warehouse = Warehouse::findOrFail($id);
        $warehouse->update($data);

        return redirect(list_url('inventory.warehouses.index'))
            ->with('success', 'Warehouse updated');
    }

    public function archive($id)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->update([
            'is_active' => 0
        ]);

        return back()->with('success', 'Warehouse archived');
    }

    public function destroy($id)
    {
        $warehouse = Warehouse::findOrFail($id);

        $used = \App\Core\Inventory\ProductStock::where('warehouse_id', $id)
            ->where('qty_on_hand', '>', 0)
            ->exists();

        if ($used) {
            return redirect()->back()
                ->with('error', 'Warehouse cannot be deleted because it already contains stock');
        }

        // Also check for ledger entries
        $hasLedger = \App\Models\InventoryLedger::where('warehouse_id', $id)->exists();
        if ($hasLedger) {
            return redirect()->back()
                ->with('error', 'Warehouse cannot be deleted because it has transaction history (ledger)');
        }

        $warehouse->delete();

        return back()->with('success', 'Warehouse deleted');
    }

    public function warehouseStock()
    {

        $products = \App\Core\Inventory\Product::where('is_active', 1)
            ->orderBy('name')
            ->get();

        $warehouses = \App\Core\Inventory\Warehouse::where('is_active', 1)->get();

        $stocks = \App\Core\Inventory\ProductStock::get();

        return view('erp.inventory.warehouses.stock', [
            'products' => $products,
            'warehouses' => $warehouses,
            'stocks' => $stocks
        ]);

    }
}
