<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use Illuminate\Http\Request;

class PreorderController extends Controller
{
    public function edit($id)
    {
        $product = Product::findOrFail($id);

        if ($product->sale_type !== 'preorder') {
            abort(404);
        }

        $stocks = ProductStock::where('product_id', $id)->get();

        if ($stocks->isEmpty()) {

            $warehouses = Warehouse::all();

            foreach ($warehouses as $wh) {
                ProductStock::create([
                    'product_id' => $id,
                    'warehouse_id' => $wh->id
                ]);
            }

            $stocks = ProductStock::where('product_id', $id)->get();
        }

        $stocks->load('warehouse');

        return view(
            'erp.inventory.products.preorder',
            compact('product', 'stocks')
        );
    }

    public function update(Request $request, $id)
    {
        foreach ($request->qty as $stockId => $value) {

            ProductStock::where('id', $stockId)
                ->update([
                    'qty_preorder_available' => $value
                ]);
        }

        return back()->with('success', 'Preorder quota updated');
    }
}
