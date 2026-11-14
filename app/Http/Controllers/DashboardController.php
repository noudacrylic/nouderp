<?php

namespace App\Http\Controllers;

use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Services\DashboardAuditService;
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
            'pendingIzin' => $isAdmin
                ? \App\Modules\SDM\Models\IzinRequest::where('status', 'pending')->count()
                : 0,
        ];

        // Bagian sensitif (keuangan & penjualan) — hanya super_admin & admin.
        if ($isAdmin) {
            $data += [
                'sales'      => $this->svc->salesSeries('monthly'),
                'kanal'      => $this->svc->salesByChannel(),
                'saldoKas'   => $this->svc->saldoKasBank(null, auth()->user()->dashboard_akun_saldo),
                'pl'         => $this->svc->profitLoss(),
                'expenses'   => $this->svc->expenses(),
                'totalAsset' => $this->svc->totalAssets(),
                'activity'   => $this->svc->recentActivity(),
            ];
        }

        return view('erp.dashboard', $data);
    }

    /** Potensi & penjualan selesai per kanal untuk rentang tanggal (AJAX). Khusus admin. */
    public function channelData(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin(), 403);

        return response()->json($this->svc->salesByChannel($request->query('start'), $request->query('end')));
    }

    /** Saldo kas/bank per tanggal (AJAX). Khusus admin. */
    public function saldoKasData(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin(), 403);

        return response()->json($this->svc->saldoKasBank($request->query('tanggal'), auth()->user()->dashboard_akun_saldo));
    }

    /** Simpan akun yang ditampilkan di kartu Saldo Kas & Bank (per pengguna). */
    public function simpanPilihanSaldo(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin(), 403);

        $data = $request->validate([
            'akun'   => 'array',
            'akun.*' => 'integer',
        ]);
        $user = auth()->user();
        $user->dashboard_akun_saldo = array_values(array_unique(array_map('intval', $data['akun'] ?? [])));
        $user->save();

        return response()->json(['ok' => true, 'pilihan' => $user->dashboard_akun_saldo]);
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

    /**
     * Halaman audit (drill-down) untuk angka dashboard: rincian transaksi/buku besar
     * pembentuk angka. Khusus admin (data keuangan sensitif).
     */
    public function audit(Request $request, DashboardAuditService $audit)
    {
        abort_unless($this->isAdmin(), 403);

        $metric = (string) $request->query('metric', '');
        abort_unless(array_key_exists($metric, DashboardAuditService::METRICS), 404);

        $data = $audit->build($metric, [
            'period'     => $request->query('period'),
            'start'      => $request->query('start'),
            'end'        => $request->query('end'),
            'account_id' => $request->query('account_id'),
        ]);

        return view('erp.dashboard.audit', ['audit' => $data, 'metric' => $metric]);
    }

    /** Hanya super_admin & admin yang boleh lihat ringkasan keuangan/penjualan. */
    private function isAdmin(): bool
    {
        $u = auth()->user();
        return $u && in_array($u->role, ['super_admin', 'admin'], true);
    }
}
