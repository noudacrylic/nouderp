<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioProductSyncService;
use Illuminate\Console\Command;

/**
 * Dorong harga produk yang berubah (ditandai observer ProductPrice) ke Jubelio.
 * Harga dasar (store_id -1). Promo tetap diatur di Jubelio.
 */
class JubelioPushPrices extends Command
{
    protected $signature = 'jubelio:push-prices';
    protected $description = 'Dorong perubahan harga ERP ke Jubelio untuk produk tersinkron';

    public function handle(JubelioProductSyncService $sync): int
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            $this->warn('Integrasi Jubelio belum aktif/dikonfigurasi. Lewati.');
            return self::SUCCESS;
        }

        $stats = $sync->pushPendingPrices();
        $this->info("Jubelio push-prices: didorong {$stats['pushed']}, dilewati {$stats['skipped']}, gagal {$stats['failed']}.");
        return self::SUCCESS;
    }
}
