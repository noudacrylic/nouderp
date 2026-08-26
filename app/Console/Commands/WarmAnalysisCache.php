<?php

namespace App\Console\Commands;

use App\Modules\Analysis\Services\BundleHppService;
use App\Modules\Analysis\Services\ProductHppService;
use App\Modules\Analysis\Services\ProductionQuotaService;
use App\Modules\Analysis\Services\ProductionTimeAnalysisService;
use App\Modules\Analysis\Support\AnalysisCache;
use Illuminate\Console\Command;

/**
 * Panaskan angka Analisa supaya bukan manusia yang membayar ongkos hitung ulang.
 *
 * Angka Analisa disimpan sampai ada data yang berubah. Yang membuka halaman TEPAT setelah
 * OP difinalisasi tetap harus menunggu hitungan penuh — dan itu justru orang yang sedang
 * bekerja. Perintah ini menjalankan hitungan itu lebih dulu di latar.
 *
 * Murah kalau tidak ada yang berubah: satu query sidik jari, lalu semuanya jawaban simpanan.
 * Dijadwalkan tiap 15 menit di routes/console.php.
 */
class WarmAnalysisCache extends Command
{
    protected $signature   = 'analisa:hangatkan {--paksa : buang simpanan lama dulu, hitung benar-benar dari nol}';
    protected $description = 'Hitung lebih dulu angka Analisa (Kuota, HPP, Bundle) supaya halamannya terbuka seketika';

    public function handle(
        AnalysisCache $cache,
        ProductHppService $hpp,
        BundleHppService $bundles,
        ProductionQuotaService $quota,
    ): int {
        if ($this->option('paksa')) {
            $cache->bump();
        }

        $mulai   = microtime(true);
        $filters = $this->filterBawaan();

        // Urutannya menirukan halaman: kuota dulu (dipakai HPP), lalu turunannya.
        $quota->build($filters);
        $hpp->all($filters);
        $bundles->all($filters);

        $this->info(sprintf('Angka Analisa siap dalam %.1f detik (sidik jari %s).',
            microtime(true) - $mulai, $cache->fingerprint()));

        return self::SUCCESS;
    }

    /**
     * Filter yang dipakai halaman saat dibuka tanpa apa-apa di URL — harus SAMA PERSIS
     * dengan ProductHppController::filters(), kalau tidak yang dipanaskan adalah entri
     * yang tidak pernah dibaca siapa pun.
     */
    private function filterBawaan(): array
    {
        return [
            'date_from'      => null,
            'date_to'        => null,
            'types'          => ProductionTimeAnalysisService::DEFAULT_TYPES,
            'include_merged' => false,
            'months'         => \App\Modules\Analysis\Services\ProductionCostRateService::DEFAULT_PERIOD_MONTHS,
            'assumption'     => false,
        ];
    }
}
