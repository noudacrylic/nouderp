<?php

namespace App\Console\Commands;

use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmAgentKnowledge;
use Illuminate\Console\Command;

/**
 * Muat pengetahuan agen dari resources/agen/{kode}-pengetahuan.md sebagai versi baru —
 * jalur deploy untuk daftar tanya-jawab yang dirawat di repo (CS Shopee). Isi yang sama
 * dengan versi aktif tidak membuat versi baru, jadi aman dijalankan tiap deploy.
 */
class CrmMuatPengetahuan extends Command
{
    protected $signature = 'crm:muat-pengetahuan {kode=cs-shopee} {--catatan= : Catatan versi}';

    protected $description = 'Muat pengetahuan agen CRM dari berkas resources/agen/{kode}-pengetahuan.md';

    public function handle(): int
    {
        $kode  = (string) $this->argument('kode');
        $agen  = CrmAgent::kode($kode);
        $jalur = resource_path("agen/{$kode}-pengetahuan.md");

        if (!$agen || !is_file($jalur)) {
            $this->error("Agen {$kode} atau berkas {$jalur} tidak ada.");
            return self::FAILURE;
        }

        $isi = trim((string) file_get_contents($jalur));
        if (trim((string) $agen->pengetahuanAktif()?->isi) === $isi) {
            $this->info('Sama dengan versi aktif — tidak ada yang diubah.');
            return self::SUCCESS;
        }

        $v = CrmAgentKnowledge::simpanVersiBaru($agen, $isi, $this->option('catatan') ?: 'Dimuat dari berkas repo', "resources/agen/{$kode}-pengetahuan.md");
        $this->info("Pengetahuan {$kode} versi {$v->versi} aktif.");

        return self::SUCCESS;
    }
}
