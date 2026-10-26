<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use Illuminate\Console\Command;

/**
 * Jaga token Shopee tetap hidup: access_token (±4 jam) diperbarui sebelum habis, dan karena
 * refresh_token ikut berganti tiap pembaruan, masa 30 harinya terus bergeser — toko tak perlu
 * dihubungkan ulang selama jadwal ini jalan.
 */
class ShopeePerbaruiToken extends Command
{
    protected $signature = 'shopee:perbarui-token';

    protected $description = 'Perbarui access token toko Shopee yang akan habis dalam ±70 menit';

    public function handle(ShopeeClient $client): int
    {
        if (!$client->siap()) {
            $this->line('Kunci Shopee belum diisi — dilewati.');
            return self::SUCCESS;
        }

        foreach (ShopeeShop::where('env', $client->env())->get() as $shop) {
            if ($shop->perluHubungkanUlang()) {
                $this->warn("Toko {$shop->shop_id}: izin kedaluwarsa, perlu dihubungkan ulang.");
                continue;
            }
            if ($shop->access_expires_at && $shop->access_expires_at->gt(now()->addMinutes(70))) {
                continue;
            }
            try {
                $client->perbarui($shop);
                $this->info("Toko {$shop->shop_id}: token diperbarui, berlaku sampai {$shop->access_expires_at->format('d/m H:i')}.");
            } catch (ShopeeException $e) {
                $this->error("Toko {$shop->shop_id}: gagal memperbarui token — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
