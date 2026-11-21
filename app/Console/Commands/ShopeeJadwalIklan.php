<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Models\ShopeeIklanKelompok;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use App\Modules\Marketplace\Shopee\Services\ShopeeIklanJadwalService;
use App\Modules\Marketplace\Shopee\Services\ShopeeIklanService;
use Illuminate\Console\Command;

/**
 * Jadwal & rotasi kelompok iklan Shopee untuk jam ini (dijalankan tiap 15 menit). Bila ada
 * kelompok Otomatis, belanja per jam hari ini ditarik dulu supaya iklan yang modal ERP-nya
 * habis cepat dijeda (data Shopee sendiri tertinggal ±1 jam).
 */
class ShopeeJadwalIklan extends Command
{
    protected $signature = 'shopee:jadwal-iklan';

    protected $description = 'Jalankan jadwal jam ramai + rotasi kelompok iklan Shopee';

    public function handle(ShopeeIklanJadwalService $jadwal, ShopeeIklanService $iklan, ShopeeClient $client): int
    {
        $adaOtomatis = ShopeeIklanKelompok::where('env', $client->env())->where('mode', 'otomatis')->exists();
        if ($adaOtomatis && $client->siap() && ($shop = ShopeeShop::aktif()) && !$shop->perluHubungkanUlang()) {
            try {
                $iklan->tarikKinerja($shop, now()->startOfDay());
            } catch (ShopeeException $e) {
                $this->warn('Belanja hari ini gagal ditarik: ' . $e->getMessage());
            }
        }

        $h = $jadwal->jalankan(now()->startOfHour());
        $this->info("Kelompok {$h['kelompok']}, aksi {$h['aksi']}, dilewati {$h['dilewati']}.");

        return self::SUCCESS;
    }
}
