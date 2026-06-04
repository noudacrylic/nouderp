<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductBundle;
use Illuminate\Http\Request;
use App\Core\Inventory\InventoryEngine;

class ProductBundleController extends Controller
{
    public function index($id)
    {
        $bundle = Product::findOrFail($id);

        if ($bundle->sale_type !== 'bundle') {
            abort(404);
        }

        // Prioritize BundleComponent, fallback to ProductBundle
        $components = \App\Core\Inventory\BundleComponent::where('bundle_product_id', $bundle->id)->get();
        if ($components->isEmpty()) {
            $components = \App\Core\Inventory\ProductBundle::where('bundle_product_id', $bundle->id)->get();
            $qtyField = 'qty_required';
        } else {
            $qtyField = 'qty';
        }

        $components = $components->load('component');
        $products = Product::where('sale_type', '!=', 'bundle')->get();

        // Hitung stok virtual bundle via InventoryEngine
        $available = app(\App\Core\Inventory\InventoryEngine::class)
            ->availableStock($bundle->id);

        return view(
            'erp.inventory.products.bundle',
            compact('bundle', 'components', 'products', 'available')
        );
    }

    public function store(Request $request, $id)
    {
        $request->validate([
            'component_product_id' => 'required',
            'qty_required' => 'required|numeric|min:0.0001'
        ]);

        ProductBundle::create([
            'bundle_product_id' => $id,
            'component_product_id' => $request->component_product_id,
            'qty_required' => $request->qty_required,
        ]);

        return back()->with('success', 'Component added');
    }
    public function components($id)
    {
        // Prioritize BundleComponent
        $components = \App\Core\Inventory\BundleComponent::with('component')
            ->where('bundle_product_id', $id)
            ->get();
        
        $qtyField = 'qty';

        if ($components->isEmpty()) {
            // Fallback to ProductBundle
            $components = \App\Core\Inventory\ProductBundle::with('component')
                ->where('bundle_product_id', $id)
                ->get();
            $qtyField = 'qty_required';
        }

        $data = [];

        foreach ($components as $c) {
            $stock = \App\Core\Inventory\ProductStock::where('product_id', $c->component_product_id)
                ->sum('qty_on_hand');

            $data[] = [
                'sku' => $c->component->sku ?? '-',
                'name' => $c->component->name ?? '-',
                'stock' => (float)$stock,
                'required' => (float)($c->{$qtyField} ?? 1)
            ];
        }

        return response()->json($data);
    }
}
