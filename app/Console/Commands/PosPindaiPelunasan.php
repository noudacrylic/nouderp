<?php

namespace App\Console\Commands;

use App\Modules\POS\Services\PelunasanNoticeService;
use Illuminate\Console\Command;

/**
 * Kabari pembeli pesanan kirim yang barangnya sudah siap tapi belum lunas.
 *
 * Seperti `pos:pindai-siap-diambil`: "pesanan menjadi Belum Lunas" tidak punya peristiwa
 * yang bisa diamati (disimpulkan dari stok, produksi, dan pembayaran), jadi pemindaian
 * berkala inilah jalur otomatisnya. Pasangan manualnya tombol "Kabari Pelunasan" di kartu.
 */
class PosPindaiPelunasan extends Command
{
    protected $signature = 'pos:pindai-pelunasan';
    protected $description = 'Kabari pembeli pesanan kirim di "Belum Lunas" untuk menyelesaikan pelunasan';

    public function handle(PelunasanNoticeService $pelunasan): int
    {
        $hasil = $pelunasan->pindaiOtomatis();

        if ($hasil['dikabari'] === 0) {
            $this->info('Tidak ada pesanan baru yang perlu dikabari pelunasan.');

            return self::SUCCESS;
        }

        $this->info("Pelunasan: {$hasil['dikabari']} pembeli dikabari — " . implode(', ', $hasil['nomor']) . '.');

        return self::SUCCESS;
    }
}
