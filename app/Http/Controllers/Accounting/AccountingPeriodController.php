<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Modules\FixedAsset\Services\FixedAssetDepreciationService;
use Illuminate\Http\Request;

class AccountingPeriodController extends Controller
{
    public function __construct(
        protected PeriodService $periodService,
        protected FixedAssetDepreciationService $depreciationService
    ) {}

    public function index(Request $request)
    {
        $periods = AccountingPeriod::orderByDesc('year')->orderByDesc('month')->paginate(24);
        return view('erp.period.index', compact('periods'));
    }

    public function ensureCurrent(Request $request)
    {
        try {
            $now = now();
            $period = $this->periodService->createPeriod($now->year, $now->month);
            $label = $now->year . '-' . str_pad((string) $now->month, 2, '0', STR_PAD_LEFT);
            $msg = $period->wasRecentlyCreated
                ? "Periode {$label} berhasil dibuat."
                : "Periode {$label} sudah ada (status: {$period->status}).";
            return redirect(list_url('accounting.period.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function preview($year, $month)
    {
        $period = AccountingPeriod::where('year', $year)->where('month', $month)->firstOrFail();
        $depreciation = $this->depreciationService->previewForPeriod($period);

        // Soft warning: akun bank/kas yang belum direkonsiliasi untuk periode ini
        $unreconciledAccounts = app(\App\Modules\Finance\Services\BankReconciliationService::class)
            ->getUnreconciledAccounts((int) $year, (int) $month);

        return view('erp.period.preview', compact('period', 'depreciation', 'unreconciledAccounts'));
    }

    public function close(Request $request, $year, $month)
    {
        try {
            $result = $this->periodService->closePeriod((int) $year, (int) $month, true);
            $dep = $result['depreciation'];
            $msg = "Periode {$year}-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . " ditutup.";
            if ($dep && $dep['count'] > 0) {
                $msg .= " Penyusutan aset tetap: {$dep['count']} aset, total Rp " . number_format((float) $dep['total_amount'], 2);
            }
            return redirect(list_url('accounting.period.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reopen(Request $request, $year, $month)
    {
        try {
            $this->periodService->reopenPeriod((int) $year, (int) $month);
            return back()->with('success', "Periode {$year}-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . " dibuka kembali.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
