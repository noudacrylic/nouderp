<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Services\ShopeeIklanJadwalService;
use Illuminate\Console\Command;

/** Jadwal & rotasi kelompok iklan Shopee untuk jam ini (mode bayangan: hanya mencatat). */
class ShopeeJadwalIklan extends Command
{
    protected $signature = 'shopee:jadwal-iklan';

    protected $description = 'Jalankan jadwal jam ramai + rotasi kelompok iklan Shopee';

    public function handle(ShopeeIklanJadwalService $jadwal): int
    {
        $h = $jadwal->jalankan(now()->startOfHour());
        $this->info("Kelompok {$h['kelompok']}, aksi {$h['aksi']}, dilewati {$h['dilewati']}.");

        return self::SUCCESS;
    }
}
