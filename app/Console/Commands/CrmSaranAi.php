<?php

namespace App\Console\Commands;

use App\Modules\CRM\Services\SaranAiService;
use Illuminate\Console\Command;

/**
 * Karyawan virtual CS Shopee & WhatsApp (mode saran): buat saran balasan untuk chat yang
 * menunggu dibalas. Tiap menit; mati selama CRM_AGEN_SARAN_AKTIF=false.
 */
class CrmSaranAi extends Command
{
    protected $signature = 'crm:saran-ai {--batas=10 : Maksimal percakapan per putaran}';

    protected $description = 'Buat saran balasan AI untuk chat Shopee & WhatsApp yang belum dibalas (mode saran)';

    public function handle(SaranAiService $saran): int
    {
        if (!$saran->aktif()) {
            $this->line('Saran AI mati (CRM_AGEN_SARAN_AKTIF) atau belum ada agen CS yang aktif.');
            return self::SUCCESS;
        }

        $dibuat = 0;
        foreach ($saran->calon((int) $this->option('batas')) as $c) {
            $s = $saran->buat($c);
            if ($s) {
                $dibuat++;
                $this->line("#{$c->id}: {$s->status}" . ($s->butir ? " ({$s->butir})" : '') . ($s->lempar_ke ? " → {$s->lempar_ke}" : ''));
            }
        }
        $this->info("Saran dibuat: {$dibuat}");

        return self::SUCCESS;
    }
}
