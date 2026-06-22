<?php

namespace App\Http\Controllers;

use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $svc,
        private FulfillmentReadinessService $fulfillment,
    ) {}

    public function index()
    {
        // Kartu operasional teratas: perlu diproses (dari pemrosesan pesanan) + status stok.
        $ops = $this->svc->stockStatusCounts()
            + ['perlu_diproses' => $this->fulfillment->counts()['perlu_diproses']];

        return view('erp.dashboard', [
            'sales'      => $this->svc->salesSeries('monthly'),
            'pl'         => $this->svc->profitLoss(),
            'expenses'   => $this->svc->expenses(),
            'totalAsset' => $this->svc->totalAssets(),
            'lowStock'   => $this->svc->lowStock(),
            'activity'   => $this->svc->recentActivity(),
            'production' => $this->svc->productionSummary(),
            'ops'        => $ops,
        ]);
    }

    /** Data grafik + top produk untuk rentang terpilih (AJAX). */
    public function chartData(Request $request): JsonResponse
    {
        $period = $request->query('period', 'monthly');
        if (!in_array($period, ['weekly', 'monthly', 'yearly', 'custom'], true)) {
            $period = 'monthly';
        }
        return response()->json($this->svc->salesSeries(
            $period,
            $request->query('start'),
            $request->query('end'),
        ));
    }
}
