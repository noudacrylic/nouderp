<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeReturService;
use Illuminate\Console\Command;

/** Pengajuan retur Shopee → shopee_returns (bahan kartu retur Meja Kerja). Baca saja. */
class ShopeeTarikRetur extends Command
{
    protected $signature = 'shopee:tarik-retur {--hari=60 : Jendela tanggal pengajuan retur ke belakang}';

    protected $description = 'Tarik status pengajuan retur Shopee ke ERP';

    public function handle(ShopeeClient $client, ShopeeReturService $svc): int
    {
        if (!$client->siap() || !($shop = ShopeeShop::aktif()) || $shop->perluHubungkanUlang()) {
            $this->warn('Toko Shopee belum terhubung.');

            return self::SUCCESS;
        }
        $this->line($svc->tarik($shop, (int) $this->option('hari')) . ' pengajuan retur disimpan.');

        return self::SUCCESS;
    }
}
