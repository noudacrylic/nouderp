<?php

namespace App\Console\Commands;

use App\Modules\CRM\Models\CrmStockWatch;
use App\Modules\CRM\Services\StockWatchService;
use Illuminate\Console\Command;

/**
 * Jaring pengaman untuk titipan "kabari kalau stoknya ada".
 *
 * Pemicu utamanya observer stok (StokTitipanObserver), yang menyala begitu
 * ledger atau reservasi bergerak. Perintah ini ada karena tidak semua cara
 * stok berubah lewat sana — impor, koreksi langsung ke tabel, atau perubahan
 * yang terjadi saat observernya kebetulan tak terpasang (perintah artisan yang
 * mematikan event, misalnya).
 *
 * Kegagalan mengabari tidak menimbulkan gejala apa pun: pelanggan cuma diam,
 * lalu belanja di tempat lain. Karena itu jaring pengamannya murah dan sering,
 * bukan menunggu ada yang curiga.
 */
class CrmCekStokTitipan extends Command
{
    protected $signature = 'crm:cek-stok-titipan';
    protected $description = 'Periksa titipan "kabari kalau stok ada" & antrekan kabarnya bila stok sudah cukup';

    public function handle(StockWatchService $titipan): int
    {
        if (! CrmStockWatch::aktif()->exists()) {
            $this->info('Tidak ada titipan stok yang aktif.');

            return self::SUCCESS;
        }

        $hasil = $titipan->periksa();

        $this->info(
            "Titipan stok: {$hasil['diperiksa']} diperiksa, {$hasil['dikabari']} diantrekan, {$hasil['dilewati']} dilewati."
        );

        return self::SUCCESS;
    }
}
