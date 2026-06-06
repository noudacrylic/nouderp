<?php

namespace App\Core\Period;

use App\Modules\FixedAsset\Models\FixedAssetSetting;
use App\Modules\FixedAsset\Services\FixedAssetDepreciationService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class PeriodService
{
    public function ensureOpen(Carbon $date): void
    {
        $period = AccountingPeriod::where('year', $date->year)
            ->where('month', $date->month)
            ->first();

        if (!$period) {
            // Auto-buat periode bila belum ada (mis. awal bulan & scheduler
            // `period:ensure-current` belum jalan / cron belum diset). Supaya transaksi
            // tidak gagal dengan "Accounting period not found.". Idempotent.
            // Periode yang DISENGAJA ditutup tetap punya row → tidak ikut auto-buka,
            // dan tetap diblok oleh cek status di bawah.
            $period = $this->createPeriod($date->year, $date->month);
        }

        if ($period->status === 'closed') {
            throw new DomainException("Accounting period is closed.");
        }
    }

    public function createPeriod(int $year, int $month): AccountingPeriod
    {
        $existing = AccountingPeriod::where('year', $year)
            ->where('month', $month)
            ->first();

        if ($existing) {
            return $existing;
        }

        return AccountingPeriod::create([
            'year' => $year,
            'month' => $month,
            'start_date' => Carbon::create($year, $month, 1),
            'end_date' => Carbon::create($year, $month, 1)->endOfMonth(),
            'status' => 'open',
        ]);
    }

    /**
     * Tutup periode bulanan + (opsional) auto-run penyusutan aset tetap.
     *
     * @return array{period:AccountingPeriod,depreciation:?array}
     */
    public function closePeriod(int $year, int $month, bool $runDepreciation = true): array
    {
        $period = AccountingPeriod::where('year', $year)
            ->where('month', $month)
            ->first();

        if (!$period) {
            throw new DomainException('Periode tidak ditemukan.');
        }
        if ($period->status === 'closed') {
            throw new DomainException('Periode sudah ditutup.');
        }

        return DB::transaction(function () use ($period, $runDepreciation) {
            $depResult = null;

            $setting = FixedAssetSetting::instance();
            if ($runDepreciation && $setting->auto_run_depreciation_on_close) {
                $depService = app(FixedAssetDepreciationService::class);
                $depResult = $depService->runForPeriod($period);
            }

            $period->status = 'closed';
            $period->closed_at = now();
            $period->save();

            return ['period' => $period, 'depreciation' => $depResult];
        });
    }

    public function reopenPeriod(int $year, int $month): AccountingPeriod
    {
        $period = AccountingPeriod::where('year', $year)
            ->where('month', $month)
            ->first();

        if (!$period) {
            throw new DomainException('Periode tidak ditemukan.');
        }
        if ($period->status !== 'closed') {
            throw new DomainException('Periode belum ditutup.');
        }

        $period->status = 'open';
        $period->closed_at = null;
        $period->save();

        return $period;
    }
}
