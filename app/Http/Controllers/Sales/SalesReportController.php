<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Core\Inventory\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SalesReportController extends Controller
{
    public function productSales(Request $request)
    {
        $dateFrom = $request->date_from
            ? Carbon::parse($request->date_from)->startOfDay()
            : Carbon::now()->startOfMonth();

        $dateTo = $request->date_to
            ? Carbon::parse($request->date_to)->endOfDay()
            : Carbon::now()->endOfDay();

        $query = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->join('products', 'products.id', '=', 'sales_order_items.product_id')
            ->where('sales_orders.status', 'confirmed')
            ->whereBetween('sales_orders.order_date', [$dateFrom, $dateTo])
            ->groupBy('sales_order_items.product_id', 'products.sku', 'products.name')
            ->select(
                'sales_order_items.product_id',
                'products.sku',
                'products.name',
                DB::raw('SUM(sales_order_items.qty) as total_qty'),
                DB::raw('SUM(sales_order_items.line_total) as total_revenue')
            );

        if ($request->product_id) {
            $query->where('sales_order_items.product_id', $request->product_id);
        }

        $rows = $query->orderByDesc('total_qty')->get();

        $grandTotalQty     = $rows->sum('total_qty');
        $grandTotalRevenue = $rows->sum('total_revenue');

        $products = Product::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name']);

        return view('erp.sales.reports.product_sales', compact(
            'rows', 'grandTotalQty', 'grandTotalRevenue',
            'dateFrom', 'dateTo', 'products'
        ));
    }
}
