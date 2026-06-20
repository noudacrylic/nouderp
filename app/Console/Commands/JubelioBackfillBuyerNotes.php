<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use Illuminate\Console\Command;

/**
 * Backfill catatan pembeli (field "note" Jubelio) untuk SO marketplace lama yang notes-nya
 * masih berisi teks identitas auto ("Pesanan Jubelio … — …"). Sekali jalan; aman diulang
 * (idempoten — hanya menyentuh SO yang masih berpola lama).
 */
class JubelioBackfillBuyerNotes extends Command
{
    protected $signature = 'jubelio:backfill-buyer-notes';
    protected $description = 'Tarik ulang catatan pembeli dari Jubelio untuk SO marketplace lama yang masih berisi teks identitas auto';

    public function handle(JubelioOrderSyncService $sync): int
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            $this->warn('Integrasi Jubelio belum aktif/dikonfigurasi. Lewati.');
            return self::SUCCESS;
        }

        $this->info('Memindai SO marketplace dengan catatan auto lama…');
        $s = $sync->backfillBuyerNotes();
        $this->info("Selesai: dipindai {$s['scanned']}, diisi catatan pembeli {$s['updated']}, "
            . "dikosongkan {$s['cleared']} (pembeli tak menulis catatan), dilewati {$s['skipped']}, error {$s['errors']}.");

        return self::SUCCESS;
    }
}
