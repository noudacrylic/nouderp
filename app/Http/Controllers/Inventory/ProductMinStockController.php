<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Core\Inventory\Product;
use Illuminate\Http\Request;

class ProductMinStockController extends Controller
{
    public function update(Request $request, int $id)
    {
        $request->validate([
            'min_stock' => 'nullable|numeric|min:0',
        ]);

        $product = Product::findOrFail($id);
        $product->update(['min_stock' => $request->min_stock ?: null]);

        return response()->json(['success' => true, 'min_stock' => $product->min_stock]);
    }
}
