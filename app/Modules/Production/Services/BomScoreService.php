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

        [$from, $to] = $this->resolvePeriod();

        // Penjualan dari SO confirmed dalam rentang periode
        $soSales = SalesOrderItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.status', 'confirmed')
            ->whereDate('sales_orders.order_date', '>=', $from)
            ->whereDate('sales_orders.order_date', '<=', $to)
            ->where('sales_order_items.product_id', $product->id)
            ->sum('sales_order_items.qty');

        // Data awal penjualan (per bulan), diproporsikan untuk bulan yang ter-overlap sebagian
        $manualSales = $this->getManualSales($product->id, $from, $to);

        $totalSales = (float) $soSales + $manualSales;
        $demand     = ($kapasitas > 0) ? $totalSales / $kapasitas : 0;

        $bobot = $this->getStockBobot($product);

        return round($demand + $bobot, 2);
    }

    /**
     * Tentukan rentang [from, to] kalkulasi score berdasarkan setting:
     *   - week   : 7 hari terakhir (hari ini & 6 hari ke belakang)
     *   - months : N bulan terakhir penuh (default, perilaku lama)
     *   - range  : rentang tanggal tetap dari setting
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(): array
    {
        $setting = ProductionSetting::first();
        $mode    = $setting->score_period_mode ?? 'months';
        $now     = Carbon::now();

        if ($mode === 'week') {
            return [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()];
        }

        if ($mode === 'range' && $setting?->score_period_start && $setting?->score_period_end) {
            return [
                $setting->score_period_start->copy()->startOfDay(),
                $setting->score_period_end->copy()->endOfDay(),
            ];
        }

        // months (default)
        $n = max(1, (int) ($setting->score_sales_period ?? 1));
        return [$now->copy()->startOfMonth()->subMonths($n - 1), $now->copy()->endOfMonth()];
    }

    /**
     * Jumlah data awal penjualan dalam rentang [from, to].
     * Data disimpan per (tahun, bulan); bulan yang hanya ter-overlap sebagian
     * (mis. mode mingguan) diproporsikan menurut jumlah hari yang tercakup.
     */
    private function getManualSales(int $productId, Carbon $from, Carbon $to): float
    {
        $total  = 0.0;
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($to)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd   = $cursor->copy()->endOfMonth();

            // Irisan bulan ini dengan rentang [from, to]
            $overlapStart = $from->greaterThan($monthStart) ? $from : $monthStart;
            $overlapEnd   = $to->lessThan($monthEnd) ? $to : $monthEnd;

            if ($overlapStart->lessThanOrEqualTo($overlapEnd)) {
                $row = ProductSalesManual::where('product_id', $productId)
                    ->where('year', $cursor->year)
                    ->where('month', $cursor->month)
                    ->first();

                if ($row) {
                    $daysInMonth = $cursor->daysInMonth;
                    $daysOverlap = $overlapStart->copy()->startOfDay()
                        ->diffInDays($overlapEnd->copy()->startOfDay()) + 1;
                    $ratio = $daysInMonth > 0 ? min(1, $daysOverlap / $daysInMonth) : 1;
                    $total += (float) $row->qty * $ratio;
                }
            }

            $cursor->addMonth();
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
