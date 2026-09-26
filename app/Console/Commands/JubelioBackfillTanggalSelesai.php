<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use Illuminate\Console\Command;

/**
 * Isi tanggal SELESAI marketplace untuk pesanan yang sudah tuntas sebelum
 * kolomnya ada. Sekali jalan, aman diulang.
 *
 * Sinkron rutin sengaja melewati pesanan berstatus terminal, jadi riwayat tidak
 * akan pernah kebagian tanggalnya sendiri — tanpa perintah ini grafik Penjualan
 * memakai cadangan (tanggal barang keluar gudang) untuk seluruh masa lalu.
 */
class JubelioBackfillTanggalSelesai extends Command
{
    protected $signature = 'jubelio:backfill-tanggal-selesai';
    protected $description = 'Tarik tanggal pesanan SELESAI dari Jubelio untuk pesanan marketplace lama (dipakai grafik Penjualan)';

    public function handle(JubelioOrderSyncService $sync): int
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            $this->warn('Integrasi Jubelio belum aktif/dikonfigurasi. Lewati.');

            return self::SUCCESS;
        }

        $this->info('Memindai pesanan marketplace yang sudah selesai tapi belum punya tanggal selesainya…');

        $s = $sync->backfillTanggalSelesai();

        $this->info("Selesai: dipindai {$s['scanned']}, terisi dari marketplace {$s['updated']}, "
            . "pakai tanggal proses {$s['fallback']}, dilewati {$s['skipped']}, error {$s['errors']}.");

        return self::SUCCESS;
    }
}
