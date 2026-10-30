<?php

namespace App\Console\Commands;

use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\KaryawanVirtual\Skills\Admin\ReturTidakJadi;
use Illuminate\Console\Command;

/**
 * Jadwal skill Manajer Admin "Selesaikan retur tidak jadi (Shopee)" — mode & jurnal kerja
 * diatur karyawan virtual (lihat ReturTidakJadi).
 */
class ShopeeReturTidakJadi extends Command
{
    protected $signature = 'shopee:retur-tidak-jadi';

    protected $description = 'Manajer Admin: selesaikan draft retur Shopee yang dibatalkan pembeli';

    public function handle(KaryawanVirtualService $kv): int
    {
        $jalan = $kv->jalankan(ReturTidakJadi::KEY, KaryawanVirtualService::PEMICU_JADWAL);
        $this->line('Mode ' . $jalan['mode'] . ' — ' . count($jalan['hasil']) . ' retur.');

        return self::SUCCESS;
    }
}
