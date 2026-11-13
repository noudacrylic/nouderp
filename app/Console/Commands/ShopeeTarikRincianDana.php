<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Services\ShopeeRincianDanaService;
use Illuminate\Console\Command;

/**
 * Rincian potongan pesanan Shopee langsung dari Shopee (escrow) — pesanan yang baru selesai,
 * dan pesanan yang returnya bergerak (penyesuaian setelah dana dilepas).
 */
class ShopeeTarikRincianDana extends Command
{
    protected $signature = 'shopee:tarik-rincian-dana {--batas=40 : Pesanan per putaran}';

    protected $description = 'Tarik rincian dana pesanan Shopee (potongan + penyesuaian retur) dari Shopee';

    public function handle(ShopeeRincianDanaService $svc): int
    {
        if (!$svc->toko()) {
            $this->line('Kunci Shopee / toko belum terhubung — dilewati.');

            return self::SUCCESS;
        }

        $h = $svc->sapu((int) $this->option('batas'));
        $this->info("Dicek {$h['dicek']}, tersimpan {$h['tersimpan']}, gagal {$h['gagal']}.");

        return self::SUCCESS;
    }
}
