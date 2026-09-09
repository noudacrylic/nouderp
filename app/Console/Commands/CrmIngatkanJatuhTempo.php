<?php

namespace App\Console\Commands;

use App\Modules\CRM\Services\DueDateReminderService;
use Illuminate\Console\Command;

/**
 * Pengingat jatuh tempo pesanan tempo: H-3 lalu hari-H.
 *
 * Sekali sehari. Jadwalnya berbasis tanggal, jadi jalan berkali-kali sehari
 * tidak menghasilkan apa pun — kunci dedupe per titik sudah menjaga tiap
 * pengingat berangkat paling banyak sekali seumur pesanan.
 */
class CrmIngatkanJatuhTempo extends Command
{
    protected $signature = 'crm:ingatkan-jatuh-tempo';
    protected $description = 'Antrekan pengingat jatuh tempo pesanan tempo (H-3 & hari-H)';

    public function handle(DueDateReminderService $tempo): int
    {
        $h = $tempo->jalankan();

        $this->info(
            "Jatuh tempo: {$h['diperiksa']} pesanan diperiksa, "
            . "{$h['diantrekan']} pengingat diantrekan, {$h['dilewati']} dilewati."
        );

        return self::SUCCESS;
    }
}
