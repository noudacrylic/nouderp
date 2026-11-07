<?php

namespace App\Console\Commands;

use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use Illuminate\Console\Command;

/** Meja Kerja: buat task dari pekerjaan baru, tutup yang beres, lanjutkan yang dibalas. */
class MejaKerjaSinkron extends Command
{
    protected $signature = 'meja-kerja:sinkron';

    protected $description = 'Sinkron Skill Admin ke Meja Kerja (task baru, selesai/batal otomatis, pengalihan)';

    public function handle(MejaKerjaService $meja): int
    {
        $h = $meja->sinkronTerkunci();
        if ($h === null) {
            $this->warn('Sinkron lain (tombol Sinkron sekarang) sedang berjalan — dilewati.');
            return self::SUCCESS;
        }
        $this->info("baru {$h['dibuat']}, selesai {$h['selesai']}, batal {$h['batal']}, dilanjutkan {$h['lanjut']}");

        return self::SUCCESS;
    }
}
