<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\ProductSalesManual;
use App\Core\Inventory\Product;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ProductSalesManualController extends Controller
{
    public function index()
    {
        $records = ProductSalesManual::with('product')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('product_id')
            ->get();

        $products = Product::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name']);

        $currentYear  = (int) now()->format('Y');
        $currentMonth = (int) now()->format('n');

        return view('erp.sales.reports.manual_sales', compact('records', 'products', 'currentYear', 'currentMonth'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'year'       => 'required|integer|min:2020|max:2099',
            'month'      => 'required|integer|min:1|max:12',
            'qty'        => 'required|numeric|min:0.0001',
            'notes'      => 'nullable|string|max:255',
        ]);

        ProductSalesManual::updateOrCreate(
            [
                'product_id' => $request->product_id,
                'year'       => $request->year,
                'month'      => $request->month,
            ],
            [
                'qty'   => $request->qty,
                'notes' => $request->notes,
            ]
        );

        return back()->with('success', 'Data penjualan manual disimpan.');
    }

    public function destroy(int $id)
    {
        ProductSalesManual::findOrFail($id)->delete();
        return back()->with('success', 'Data penjualan manual dihapus.');
    }
}
