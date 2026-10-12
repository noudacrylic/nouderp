<?php

namespace App\Console\Commands;

use App\Modules\Finance\Services\PphFinalHutangService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** Bukukan hutang PPh Final UMKM (Dr 5012 / Cr 2114) satu masa. Bawaan: masa bulan lalu. */
class BukukanHutangPphFinal extends Command
{
    protected $signature = 'pajak:bukukan-hutang {--masa= : Masa pajak YYYY-MM (bawaan: bulan lalu)}';

    protected $description = 'Bukukan hutang PPh Final UMKM 0,5% setor sendiri ke akun 2114 untuk satu masa';

    public function handle(PphFinalHutangService $hutang): int
    {
        $masa = $this->option('masa')
            ? Carbon::createFromFormat('Y-m', $this->option('masa'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        $nilai = $hutang->bukukan($masa);
        $this->info('Masa ' . $masa->format('Y-m') . ': ' . ($nilai == 0 ? 'sudah sesuai' : 'dijurnal ' . number_format($nilai, 0, ',', '.')));

        return self::SUCCESS;
    }
}
