<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();

        // Produk diarsipkan tidak ditampilkan di halaman Stok.
        $query->where('is_active', 1);

        // Jasa & non-stok memang tidak punya stok — sembunyikan dari halaman Stok.
        $query->whereNotIn('sale_type', ['service', 'non_stock']);

        // SEARCH
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('sku', 'like', '%' . $request->search . '%')
                    ->orWhere('name', 'like', '%' . $request->search . '%');
            });
        }

        // FILTER TYPE
        if ($request->type) {
            $query->where('sale_type', $request->type);
        }

        $products = $query->with(['bundleItems', 'bundleComponents'])->orderBy('sale_type')->orderBy('name')->get();

        $productIds = $products->pluck('id')->toArray();
        $componentProductIds = $products->flatMap(function($p) {
            return $p->bundleItems->isNotEmpty() 
                ? $p->bundleItems->pluck('component_product_id') 
                : $p->bundleComponents->pluck('component_product_id');
        })->unique()->toArray();

        $allRelevantProductIds = array_unique(array_merge($productIds, $componentProductIds));

        // 🟢 Pre-aggregate data untuk performa tinggi (Menghindari N+1)
        $physicalStocksMap = \App\Core\Inventory\ProductStock::whereIn('product_id', $allRelevantProductIds)
            ->groupBy('product_id')
            ->select('product_id', \Illuminate\Support\Facades\DB::raw('SUM(qty_on_hand) as total'))
            ->pluck('total', 'product_id');

        $sellableStocksMap = \App\Core\Inventory\ProductStock::whereIn('product_id', $allRelevantProductIds)
            ->whereHas('warehouse', fn($q) => $q->where('is_sellable', true))
            ->groupBy('product_id')
            ->select('product_id', \Illuminate\Support\Facades\DB::raw('SUM(qty_on_hand) as total'))
            ->pluck('total', 'product_id');

        $nonSellableStocksMap = \App\Core\Inventory\ProductStock::whereIn('product_id', $allRelevantProductIds)
            ->whereHas('warehouse', fn($q) => $q->where('is_sellable', false))
            ->groupBy('product_id')
            ->select('product_id', \Illuminate\Support\Facades\DB::raw('SUM(qty_on_hand) as total'))
            ->pluck('total', 'product_id');

        $reservationsMap = \App\Core\Inventory\StockReservation::whereIn('product_id', $allRelevantProductIds)
            ->where('status', 'active')
            ->groupBy('product_id')
            ->select('product_id', \Illuminate\Support\Facades\DB::raw('SUM(qty) as total'))
            ->pluck('total', 'product_id');

        // 🔥 Rule Baru: 'DIKIRIM' hanya yang status=posted tapi BELUM invoice_id (belum jadi revenue)
        $shippedQtyMap = \Illuminate\Support\Facades\DB::table('sales_delivery_items')
            ->join('sales_deliveries', 'sales_deliveries.id', '=', 'sales_delivery_items.sales_delivery_id')
            ->whereIn('sales_delivery_items.product_id', $allRelevantProductIds)
            ->where('sales_deliveries.status', 'posted')
            ->whereNull('sales_deliveries.invoice_id')
            ->groupBy('sales_delivery_items.product_id')
            ->select('sales_delivery_items.product_id', \Illuminate\Support\Facades\DB::raw('SUM(sales_delivery_items.qty) as total'))
            ->pluck('total', 'product_id');

        // Kurangi DIKIRIM dengan qty yang sudah diretur via Retur SO (semua kondisi: utuh/rusak/perbaikan)
        // Retur Invoice tidak perlu dikurangi karena invoice_id != null → sudah exclude dari DIKIRIM
        $returnedFromSOMap = \Illuminate\Support\Facades\DB::table('sales_return_items')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->whereIn('sales_return_items.product_id', $allRelevantProductIds)
            ->where('sales_returns.status', 'posted')
            ->whereNotNull('sales_returns.sales_order_id')
            ->whereNull('sales_returns.invoice_id')
            ->groupBy('sales_return_items.product_id')
            ->select('sales_return_items.product_id', \Illuminate\Support\Facades\DB::raw('SUM(sales_return_items.qty) as total'))
            ->pluck('total', 'product_id');

        $stocks = $products->map(function ($product) use ($physicalStocksMap, $sellableStocksMap, $nonSellableStocksMap, $reservationsMap, $shippedQtyMap, $returnedFromSOMap) {
            if ($product->sale_type === 'bundle') {
                
                $components = $product->bundleItems->isNotEmpty() ? $product->bundleItems : $product->bundleComponents;
                $maxBundle = PHP_INT_MAX;
                $totalReservedBundles = 0;
                $stokTersedia = null;

                foreach ($components as $component) {
                    $qtyField     = isset($component->qty_required) ? 'qty_required' : 'qty';
                    $qtyRequired  = $component->{$qtyField} ?? 1;
                    $cid          = $component->component_product_id;

                    $physicalStockValue = $physicalStocksMap[$cid] ?? 0;
                    $sellableStockValue = $sellableStocksMap[$cid] ?? 0;
                    $reservedQtyValue   = $reservationsMap[$cid] ?? 0;

                    if ($qtyRequired > 0) {
                        $maxByPhysical = (int) floor($physicalStockValue / $qtyRequired);
                        $maxBundle = min($maxBundle, $maxByPhysical);
                        
                        $maxReserved = (int) floor($reservedQtyValue / $qtyRequired);
                        $totalReservedBundles = max($totalReservedBundles, $maxReserved);
                        
                        // Stok tersedia bundle dihitung dari komponen yang sellable
                        $availableForThisComponent = $sellableStockValue - $reservedQtyValue;
                        $bundleAvailableByComponent = (int) floor(max(0, $availableForThisComponent) / $qtyRequired);
                        $stokTersedia = is_null($stokTersedia) ? $bundleAvailableByComponent : min($stokTersedia, $bundleAvailableByComponent);
                    }
                }

                $stokFisik    = $maxBundle === PHP_INT_MAX ? 0 : $maxBundle;
                $stokDipesan  = $totalReservedBundles;
                $stokCadangan = 0;
                $stokTersedia = $stokTersedia ?? 0;
                $shippedQty   = 0; // Bundle tidak di-sum langsung di core items (komponennya yang di-sum)

            } else {
                $pid = $product->id;
                $stokFisik    = $physicalStocksMap[$pid] ?? 0;
                $stokCadangan = $nonSellableStocksMap[$pid] ?? 0;
                $stokDipesan  = $reservationsMap[$pid] ?? 0;
                $sellableQty  = $sellableStocksMap[$pid] ?? 0;
                $stokTersedia = $sellableQty - $stokDipesan + ($product->preorder_stock ?? 0);
                $shippedQty   = max(0, ($shippedQtyMap[$pid] ?? 0) - ($returnedFromSOMap[$pid] ?? 0));
            }

            $minStock = $product->min_stock;
            $stockStatus = 'ok';
            // Status stok berbasis stok TERSEDIA (bukan stok fisik): produk dianggap
            // menipis/habis bila yang benar-benar bisa dijual sudah di bawah min stok.
            if ($product->sale_type === 'preorder') {
                $stockStatus = 'preorder';
            } elseif ($stokTersedia < 0) {
                $stockStatus = 'minus';
            } elseif ($stokTersedia == 0) {
                $stockStatus = 'habis';
            } elseif ($minStock !== null && $stokTersedia <= (float) $minStock) {
                $stockStatus = 'menipis';
            }

            return (object) [
                'id'          => $product->id,
                'sku'         => $product->sku,
                'name'        => $product->name,
                'type'        => $product->sale_type,
                'qty_on_hand' => $stokFisik,
                'reserved'    => $stokCadangan,
                'ordered'     => $stokDipesan,
                'shipped'     => $shippedQty,
                'available'   => $stokTersedia,
                'min_stock'   => $minStock,
                'stock_status'=> $stockStatus,
            ];
        });

        // FILTER STATUS (post-aggregation): ada / menipis / habis / minus
        // 'ada' = stock_status 'ok' (ready & cukup di atas min stock)
        if ($request->filled('status')) {
            $statusMap = ['ada' => 'ok'];
            $target = $statusMap[$request->status] ?? $request->status;
            $stocks = $stocks->filter(fn($s) => $s->stock_status === $target)->values();
        }

        return view('erp.inventory.stocks.index', compact('stocks'));
    }

    public function getStock(Request $request)
    {
        $stock = ProductStock::where([
            'product_id' => $request->product_id,
            'warehouse_id' => $request->warehouse_id
        ])->first();

        return response()->json([
            'qty' => (float) ($stock->qty_on_hand ?? 0)
        ]);
    }
}
