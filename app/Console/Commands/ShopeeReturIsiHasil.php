<?php

namespace App\Console\Commands;

use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\KaryawanVirtual\Skills\Admin\ReturIsiHasil;
use Illuminate\Console\Command;

/**
 * Jadwal skill Manajer Admin "Isi hasil retur Shopee ke draft" — mode & jurnal kerja diatur
 * karyawan virtual (lihat ReturIsiHasil).
 */
class ShopeeReturIsiHasil extends Command
{
    protected $signature = 'shopee:retur-isi-hasil';

    protected $description = 'Manajer Admin: isi draft retur Shopee yang sudah final dari rincian dana Shopee';

    public function handle(KaryawanVirtualService $kv): int
    {
        $jalan = $kv->jalankan(ReturIsiHasil::KEY, KaryawanVirtualService::PEMICU_JADWAL);
        $this->line('Mode ' . $jalan['mode'] . ' — ' . count($jalan['hasil']) . ' retur.');

        return self::SUCCESS;
    }
}
