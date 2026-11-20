<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use App\Modules\Marketplace\Shopee\Services\ShopeeIklanService;
use Illuminate\Console\Command;

/**
 * Iklan Shopee tahap 1 (baca saja): daftar iklan, saldo iklan, dan kinerja per jam hari ini +
 * --hari hari ke belakang. Tiap jam menarik kemarin juga; harian menarik 7 hari karena pesanan
 * atribusi "broad" masih bisa bertambah beberapa hari setelah klik.
 */
class ShopeeTarikIklan extends Command
{
    protected $signature = 'shopee:tarik-iklan {--hari=1 : Berapa hari ke belakang selain hari ini}';

    protected $description = 'Tarik daftar & kinerja per jam iklan Shopee (baca saja)';

    public function handle(ShopeeClient $client, ShopeeIklanService $iklan): int
    {
        if (!$client->siap() || !($shop = ShopeeShop::aktif()) || $shop->perluHubungkanUlang()) {
            $this->line('Kunci Shopee / toko belum terhubung — dilewati.');

            return self::SUCCESS;
        }

        try {
            $h = $iklan->tarik($shop, max(0, (int) $this->option('hari')));
        } catch (ShopeeException $e) {
            $this->error('Gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Iklan {$h['iklan']}, {$h['hari']} hari, {$h['baris']} baris per jam.");
        foreach ($h['galat'] as $g) {
            $this->warn($g);
        }

        return self::SUCCESS;
    }
}
