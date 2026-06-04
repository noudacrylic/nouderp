<?php

namespace App\Console\Commands;

use App\Core\Period\PeriodService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Pastikan periode akuntansi untuk bulan berjalan sudah terbentuk.
 *
 * Dijadwalkan tiap tanggal 1 jam 00:01 (lihat routes/console.php) agar
 * transaksi awal bulan tidak gagal dengan "Accounting period not found.".
 * Idempotent — aman dijalankan berulang (PeriodService::createPeriod skip jika sudah ada).
 *
 * Logika sama dipanggil dari tombol manual di halaman Accounting Period.
 */
class EnsureCurrentPeriod extends Command
{
    protected $signature = 'period:ensure-current {--month= : Bulan target (YYYY-MM), default bulan berjalan}';
    protected $description = 'Buat periode akuntansi bulan berjalan jika belum ada (idempotent)';

    public function handle(PeriodService $periodService): int
    {
        $target = $this->option('month')
            ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : Carbon::now()->startOfMonth();

        $period = $periodService->createPeriod($target->year, $target->month);

        $label = $target->year . '-' . str_pad((string) $target->month, 2, '0', STR_PAD_LEFT);
        $this->info("Periode {$label} siap (status: {$period->status}).");

        return self::SUCCESS;
    }
}
