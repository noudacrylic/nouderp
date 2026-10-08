<?php

namespace App\Console\Commands;

use App\Modules\CRM\Services\TutupOtomatisService;
use Illuminate\Console\Command;

/**
 * Tutup chat berlabel Selesai & chat distributor yang sudah sepi 3 hari.
 * Tiap jam — ketepatan menit tidak berarti apa pun untuk ambang berhitungan hari.
 */
class CrmTutupOtomatis extends Command
{
    protected $signature = 'crm:tutup-otomatis';
    protected $description = 'Tutup chat Selesai & distributor yang sepi lebih dari 3 hari';

    public function handle(TutupOtomatisService $tutup): int
    {
        $this->info('Chat ditutup otomatis: ' . $tutup->jalankan() . '.');

        return self::SUCCESS;
    }
}
