<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use Illuminate\Console\Command;

/**
 * Tarik pesanan Jubelio (dibayar → selesai) dan jalankan pipeline ERP
 * (SO+DP → SJ → Invoice). Andalan untuk localhost; webhook hanya akselerator.
 */
class JubelioSyncOrders extends Command
{
    protected $signature = 'jubelio:sync-orders';
    protected $description = 'Sinkron pesanan Jubelio menjadi SO + DP, Surat Jalan, dan Invoice di ERP';

    public function handle(JubelioOrderSyncService $sync): int
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            $this->warn('Integrasi Jubelio belum aktif/dikonfigurasi. Lewati.');
            return self::SUCCESS;
        }

        $stats = $sync->syncOrders();
        $this->info("Jubelio sync-orders selesai: diproses {$stats['processed']}, error {$stats['errors']}.");

        // Tarik juga permintaan pembatalan dari pembeli (tab "Pembatalan").
        $cancel = $sync->syncCancellationRequests();
        $this->info("Permintaan batal: ditandai {$cancel['flagged']}, dibersihkan {$cancel['cleared']}.");

        return self::SUCCESS;
    }
}
