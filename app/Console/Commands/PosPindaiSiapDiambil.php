<?php

namespace App\Console\Commands;

use App\Modules\POS\Services\PickupReadyService;
use Illuminate\Console\Command;

/**
 * Kabari pembeli ambil-di-toko begitu barangnya siap.
 *
 * Kesiapan pesanan tidak punya peristiwa yang bisa diamati: ia disimpulkan dari stok,
 * pembayaran, dan order produksi yang semuanya bergerak sendiri-sendiri
 * (FulfillmentReadinessService menghitungnya ulang setiap kali layar dibuka). Karena tidak
 * ada satu titik kode yang bisa dipanggil "saat pesanan menjadi siap", pemindaian berkala
 * inilah jalur otomatisnya — dan tombol "Siap Diambil" di kartu adalah pasangan manualnya.
 */
class PosPindaiSiapDiambil extends Command
{
    protected $signature = 'pos:pindai-siap-diambil';
    protected $description = 'Tandai pesanan Ambil di Toko yang barangnya sudah siap & kabari pembelinya';

    public function handle(PickupReadyService $siap): int
    {
        $hasil = $siap->pindaiOtomatis();

        if ($hasil['ditandai'] === 0) {
            $this->info('Tidak ada pesanan ambil-di-toko baru yang siap diambil.');

            return self::SUCCESS;
        }

        $this->info("Siap diambil: {$hasil['ditandai']} pesanan ditandai — " . implode(', ', $hasil['nomor']) . '.');

        return self::SUCCESS;
    }
}
