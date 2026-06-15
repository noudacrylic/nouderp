<?php

namespace App\Console\Commands;

use App\Modules\SDM\Services\PeriodePenggajianService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Pastikan periode penggajian/absensi untuk bulan berjalan sudah terbentuk.
 *
 * Dijadwalkan tiap tanggal 1 jam 00:01 (lihat routes/console.php) supaya scan
 * fingerprint awal bulan langsung punya periode untuk mengisi absensi.
 * Idempotent — aman dijalankan berulang. Logika sama dipanggil dari tombol
 * manual "Buat Periode Bulan Ini" di halaman Pengaturan Periode.
 */
class EnsurePeriodePenggajian extends Command
{
    protected $signature = 'periode-gaji:ensure-current {--month= : Bulan target (YYYY-MM), default bulan berjalan}';
    protected $description = 'Buat periode penggajian/absensi bulan berjalan jika belum ada (idempotent)';

    public function handle(PeriodePenggajianService $service): int
    {
        $target = $this->option('month')
            ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : Carbon::now()->startOfMonth();

        $periode = $service->ensureForMonth((int) $target->month, (int) $target->year);
        $this->info("Periode {$periode->label} siap (status: {$periode->status}).");

        return self::SUCCESS;
    }
}
