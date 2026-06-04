<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Core\Inventory\Product;
use App\Models\InventoryLedger;
use App\Models\InventoryCostLayer;

class InventoryReportController extends Controller
{
    public function valuation()
    {
        $products = Product::with(['costLayers'])->get();
        return view('erp.inventory.reports.valuation', compact('products'));
    }

    public function stockCard(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);
        $ledgers = InventoryLedger::where('product_id', $productId)
            ->orderBy('created_at')
            ->get();

        return view('erp.inventory.reports.stock_card', compact('ledgers', 'product'));
    }
}
