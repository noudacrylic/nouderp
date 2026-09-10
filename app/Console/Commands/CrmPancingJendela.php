<?php

namespace App\Console\Commands;

use App\Modules\CRM\Services\PancinganJendelaService;
use Illuminate\Console\Command;

/**
 * Antrekan pancingan sebelum jendela 24 jam sebuah percakapan habis.
 *
 * Hanya mengantrekan; yang mengirim `crm:kirim-notifikasi` (tiap 5 menit),
 * sama seperti notifikasi pesanan lainnya.
 *
 * Tiap 15 menit. Jendela habis di menit mana saja, dan jarak antar-jalan
 * itulah yang menentukan seberapa mepet pancingan terkirim: tiap jam berarti
 * ada percakapan yang dipancing lima menit sebelum tutup, terlalu mepet untuk
 * sempat ditekan.
 */
class CrmPancingJendela extends Command
{
    protected $signature = 'crm:pancing-jendela';
    protected $description = 'Antrekan pancingan untuk percakapan yang jendela 24 jam-nya hampir habis';

    public function handle(PancinganJendelaService $pancingan): int
    {
        $h = $pancingan->jalankan();

        if ($h['dilewati']) {
            $this->line('Dilewati: ' . $h['dilewati'] . '.');

            return self::SUCCESS;
        }

        $this->info(
            "Pancingan jendela: {$h['diperiksa']} percakapan hampir habis, "
            . "{$h['diantrekan']} diantrekan."
        );

        return self::SUCCESS;
    }
}
