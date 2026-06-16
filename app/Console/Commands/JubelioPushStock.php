<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioStockSyncService;
use Illuminate\Console\Command;

/**
 * Dorong stok produk yang berubah (ditandai observer) ke Jubelio. Jalan sering
 * (mis. tiap 5 menit) sebagai "trigger" near-realtime pengurangan/penambahan stok.
 */
class JubelioPushStock extends Command
{
    protected $signature = 'jubelio:push-stock';
    protected $description = 'Dorong perubahan stok ERP (available) ke Jubelio untuk produk tersinkron';

    public function handle(JubelioStockSyncService $sync): int
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            $this->warn('Integrasi Jubelio belum aktif/dikonfigurasi. Lewati.');
            return self::SUCCESS;
        }

        $stats = $sync->pushPending();
        $unmatched = $stats['skipped_unmatched'] ?? 0;
        $this->info("Jubelio push-stock: didorong {$stats['pushed']}, sudah sama {$stats['skipped']}, dilewati(belum match/location) {$unmatched}, gagal {$stats['failed']}.");
        return self::SUCCESS;
    }
}
