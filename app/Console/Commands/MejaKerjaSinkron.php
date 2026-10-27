<?php

namespace App\Console\Commands;

use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use Illuminate\Console\Command;

/** Meja Kerja: buat task dari pekerjaan baru, tutup yang beres, lanjutkan yang dibalas, alihkan yang tak hadir. */
class MejaKerjaSinkron extends Command
{
    protected $signature = 'meja-kerja:sinkron';

    protected $description = 'Sinkron Skill Admin ke Meja Kerja (task baru, selesai/batal otomatis, pengalihan)';

    public function handle(MejaKerjaService $meja): int
    {
        $h = $meja->sinkron();
        $this->info("baru {$h['dibuat']}, selesai {$h['selesai']}, batal {$h['batal']}, dilanjutkan {$h['lanjut']}, dialihkan {$h['alih']}");

        return self::SUCCESS;
    }
}
