<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use Illuminate\Console\Command;

/**
 * Tarik retur Jubelio yang belum diproses dan buat SalesReturn DRAFT dari SO ERP
 * (draft karena kondisi barang harus dicek dulu sebelum di-post).
 */
class JubelioSyncReturns extends Command
{
    protected $signature = 'jubelio:sync-returns';
    protected $description = 'Sinkron retur Jubelio menjadi draft Retur Penjualan di ERP';

    public function handle(JubelioOrderSyncService $sync): int
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            $this->warn('Integrasi Jubelio belum aktif/dikonfigurasi. Lewati.');
            return self::SUCCESS;
        }

        $stats = $sync->syncReturns();
        $this->info("Jubelio sync-returns selesai: draft dibuat {$stats['created']}, dilewati {$stats['skipped']}.");
        return self::SUCCESS;
    }
}
