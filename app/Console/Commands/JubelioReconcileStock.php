<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioStockSyncService;
use Illuminate\Console\Command;

/**
 * Rekonsiliasi stok penuh ERP ↔ Jubelio (ERP menang). Jalan tiap 2 jam untuk
 * menambal drift (mis. akibat reservasi SO atau adjustment manual di Jubelio).
 */
class JubelioReconcileStock extends Command
{
    protected $signature = 'jubelio:reconcile-stock';
    protected $description = 'Samakan stok available semua produk tersinkron ke Jubelio (ERP sumber kebenaran)';

    public function handle(JubelioStockSyncService $sync): int
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            $this->warn('Integrasi Jubelio belum aktif/dikonfigurasi. Lewati.');
            return self::SUCCESS;
        }

        $stats = $sync->reconcileAll();
        $this->info("Jubelio reconcile-stock: dikoreksi {$stats['pushed']}, sudah sama {$stats['skipped']}, gagal {$stats['failed']}.");
        return self::SUCCESS;
    }
}
