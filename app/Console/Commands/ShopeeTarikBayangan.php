<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use App\Modules\Marketplace\Shopee\Services\ShopeeKatalogService;
use App\Modules\Marketplace\Shopee\Services\ShopeePesananService;
use Illuminate\Console\Command;

/**
 * Tahap 2 Shopee — tarik katalog & pesanan langsung lalu bandingkan dengan jalur Jubelio.
 * BACA SAJA: tidak menulis SO, stok, maupun ke Shopee.
 */
class ShopeeTarikBayangan extends Command
{
    protected $signature = 'shopee:tarik-bayangan
                            {--katalog : Tarik katalog (item + varian) dan perbarui peta SKU}
                            {--jam=3 : Pesanan yang diperbarui dalam N jam terakhir}
                            {--dari= : Tanggal awal (Y-m-d), menggantikan --jam}';

    protected $description = 'Tarik pesanan (dan katalog) Shopee langsung untuk dibandingkan dengan SO Jubelio';

    public function handle(ShopeeClient $client, ShopeeKatalogService $katalog, ShopeePesananService $pesanan): int
    {
        if (!$client->siap() || !($shop = ShopeeShop::aktif())) {
            $this->line('Kunci Shopee / toko belum terhubung — dilewati.');
            return self::SUCCESS;
        }

        try {
            if ($this->option('katalog')) {
                $k = $katalog->tarik($shop);
                $this->info("Katalog: {$k['item']} item, {$k['varian']} varian — terpeta {$k['terpeta']}, belum {$k['belum']}.");
            }

            $dari = $this->option('dari') ? now()->parse($this->option('dari'))->startOfDay() : now()->subHours((int) $this->option('jam'));
            $p = $pesanan->tarik($shop, $dari, now());
            $ringkas = collect($p['hasil'])->map(fn ($n, $h) => "$h $n")->implode(', ');
            $this->info("Pesanan: {$p['pesanan']} ditarik" . ($ringkas ? " — $ringkas" : '') . '.');
        } catch (ShopeeException $e) {
            $this->error('Gagal: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
