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
        $isAdmin = $this->isAdmin();

        // Kartu operasional teratas: perlu diproses (dari pemrosesan pesanan) + status stok.
        $ops = $this->svc->stockStatusCounts()
            + ['perlu_diproses' => $this->fulfillment->counts()['perlu_diproses']];

        // Bagian operasional — terlihat untuk semua role (termasuk 'user').
        $data = [
            'isAdmin'    => $isAdmin,
            'lowStock'   => $this->svc->lowStock(),
            'production' => $this->svc->productionSummary(),
            'ops'        => $ops,
        ];

        // Bagian sensitif (keuangan & penjualan) — hanya super_admin & admin.
        if ($isAdmin) {
            $data += [
                'sales'      => $this->svc->salesSeries('monthly'),
                'pl'         => $this->svc->profitLoss(),
                'expenses'   => $this->svc->expenses(),
                'totalAsset' => $this->svc->totalAssets(),
                'activity'   => $this->svc->recentActivity(),
            ];
        }

        return view('erp.dashboard', $data);
    }

    /** Data grafik + top produk untuk rentang terpilih (AJAX). Khusus admin (data penjualan sensitif). */
    public function chartData(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin(), 403);

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

    /** Hanya super_admin & admin yang boleh lihat ringkasan keuangan/penjualan. */
    private function isAdmin(): bool
    {
        $u = auth()->user();
        return $u && in_array($u->role, ['super_admin', 'admin'], true);
    }
}
