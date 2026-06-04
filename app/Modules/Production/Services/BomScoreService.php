<?php

namespace App\Modules\Production\Services;

use App\Models\ProductionSetting;
use App\Models\ProductSalesManual;
use App\Modules\Production\Models\Bom;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Modules\Sales\Models\SalesOrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BomScoreService
{
    public function calculate(Bom $bom): float
    {
        $bom->loadMissing(['outputs.product']);

        $mainOutput = $bom->outputs->firstWhere('output_type', 'main');
        if (!$mainOutput || !$mainOutput->product) {
            return 0;
        }

        $product      = $mainOutput->product;
        $qtyPerCycle  = (float) $mainOutput->qty_per_cycle;
        $typicalCycles = (int) ($bom->typical_cycles ?? 1);

        $kapasitas = $typicalCycles * $qtyPerCycle;

        $period  = ProductionSetting::getSalesPeriod();
        $from    = Carbon::now()->startOfMonth()->subMonths($period - 1);

        // Penjualan dari SO confirmed
        $soSales = SalesOrderItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.status', 'confirmed')
            ->whereDate('sales_orders.order_date', '>=', $from)
            ->where('sales_order_items.product_id', $product->id)
            ->sum('sales_order_items.qty');

        // Penjualan manual dalam range bulan yang sama
        $manualSales = $this->getManualSales($product->id, $from, $period);

        $totalSales = (float) $soSales + $manualSales;
        $demand     = ($kapasitas > 0) ? $totalSales / $kapasitas : 0;

        $bobot = $this->getStockBobot($product);

        return round($demand + $bobot, 2);
    }

    private function getManualSales(int $productId, Carbon $from, int $months): float
    {
        $ranges = [];
        for ($i = 0; $i < $months; $i++) {
            $d = $from->copy()->addMonths($i);
            $ranges[] = ['year' => $d->year, 'month' => $d->month];
        }

        $total = 0;
        foreach ($ranges as $r) {
            $row = ProductSalesManual::where('product_id', $productId)
                ->where('year', $r['year'])
                ->where('month', $r['month'])
                ->first();
            if ($row) {
                $total += (float) $row->qty;
            }
        }
        return $total;
    }

    private function getStockBobot(Product $product): float
    {
        if ($product->sale_type === 'preorder') {
            return 300;
        }

        $qtyOnHand = (float) ProductStock::where('product_id', $product->id)->sum('qty_on_hand');

        if ($qtyOnHand < 0) {
            return 300;
        }
        if ($qtyOnHand == 0) {
            return 200;
        }
        if ($product->min_stock !== null && $qtyOnHand <= (float) $product->min_stock) {
            return 100;
        }
        return 0;
    }

    public function recalculateAll(): void
    {
        Bom::with(['outputs.product'])
            ->get()
            ->each(function (Bom $bom) {
                $score = $this->calculate($bom);
                $bom->update(['score' => $score]);
            });
    }
}
