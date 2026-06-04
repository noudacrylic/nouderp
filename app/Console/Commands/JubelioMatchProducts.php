<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioProductSyncService;
use Illuminate\Console\Command;

/**
 * Cocokkan produk ERP (sync_to_jubelio) ke item Jubelio via SKU; simpan item_id
 * & item_group_id. Produk yang tak ketemu dilaporkan untuk ditinjau.
 */
class JubelioMatchProducts extends Command
{
    protected $signature = 'jubelio:match-products {--all : Cocokkan ulang semua, termasuk yang sudah ter-match}';
    protected $description = 'Cocokkan produk ERP ke item Jubelio berdasarkan SKU';

    public function handle(JubelioProductSyncService $sync): int
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            $this->warn('Integrasi Jubelio belum aktif/dikonfigurasi. Lewati.');
            return self::SUCCESS;
        }

        $res = $sync->matchAll(onlyMissing: !$this->option('all'));
        $this->info("Match selesai: cocok {$res['matched']}, tidak ketemu {$res['unmatched']}.");
        if (!empty($res['unmatched_skus'])) {
            $this->warn('SKU tidak ditemukan di Jubelio: ' . implode(', ', $res['unmatched_skus']));
        }
        return self::SUCCESS;
    }
}
