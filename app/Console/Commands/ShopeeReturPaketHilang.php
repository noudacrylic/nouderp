<?php

namespace App\Console\Commands;

use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\KaryawanVirtual\Skills\Admin\ReturPaketHilang;
use Illuminate\Console\Command;

/**
 * Jadwal skill Manajer Admin "Selesaikan paket hilang yang diganti Shopee" — mode & jurnal
 * kerja diatur karyawan virtual (lihat ReturPaketHilang).
 */
class ShopeeReturPaketHilang extends Command
{
    protected $signature = 'shopee:retur-paket-hilang';

    protected $description = 'Manajer Admin: posting draft retur Shopee paket hilang yang sudah diganti Shopee';

    public function handle(KaryawanVirtualService $kv): int
    {
        $jalan = $kv->jalankan(ReturPaketHilang::KEY, KaryawanVirtualService::PEMICU_JADWAL);
        $this->line('Mode ' . $jalan['mode'] . ' — ' . count($jalan['hasil']) . ' retur.');

        return self::SUCCESS;
    }
}
