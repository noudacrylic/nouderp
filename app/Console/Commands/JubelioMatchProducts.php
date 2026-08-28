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

        $res    = $sync->matchAll(onlyMissing: !$this->option('all'));
        $errors = $res['errors'] ?? [];

        $this->info(sprintf(
            'Match selesai: cocok %d, tidak ada di Jubelio %d, gagal karena gangguan API %d.',
            $res['matched'], $res['unmatched'], count($errors)
        ));

        if (!empty($res['unmatched_skus'])) {
            $this->warn('SKU tidak ada di Jubelio (perbaiki datanya): ' . implode(', ', $res['unmatched_skus']));
        }

        // Gangguan API bukan salah data — dilaporkan terpisah supaya tidak memicu orang
        // mengedit SKU yang sebenarnya sudah benar. Cukup jalankan ulang perintahnya.
        foreach ($errors as $baris) {
            $this->error('Gangguan Jubelio: ' . $baris);
        }
        if ($errors) {
            $this->line('Yang di atas belum tentu salah SKU — jalankan ulang perintah ini nanti.');
        }

        return self::SUCCESS;
    }
}
