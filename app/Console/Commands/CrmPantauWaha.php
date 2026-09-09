<?php

namespace App\Console\Commands;

use App\Modules\CRM\Services\WahaHealthService;
use Illuminate\Console\Command;

/**
 * Denyut jantung sesi WhatsApp tak resmi (WAHA), tiap 5 menit.
 *
 * Perlu terjadwal, bukan diperiksa saat mengirim saja: antara dua pesanan bisa
 * lewat berjam-jam, dan selama itu tak ada seorang pun yang tahu sesinya sudah
 * putus. Yang ingin dicegah adalah kabar pelanggan berhenti diam-diam
 * sepanjang akhir pekan.
 *
 * Fitur otomatis wajib punya pemicu tangan juga — di sini pemicunya adalah
 * tombol "Uji Sesi WAHA" di Pengaturan CRM, yang memanggil adapter yang sama.
 */
class CrmPantauWaha extends Command
{
    protected $signature = 'crm:pantau-waha';
    protected $description = 'Periksa sesi WAHA; peringatkan lewat Telegram (beserta QR) bila putus';

    public function handle(WahaHealthService $kesehatan): int
    {
        $hasil = $kesehatan->periksa();

        if ($hasil['dilewati']) {
            $this->info('Jalur notifikasi bukan WAHA — tidak ada yang dipantau.');

            return self::SUCCESS;
        }

        if ($hasil['siap']) {
            $this->info('Sesi WAHA sehat (WORKING).');

            return self::SUCCESS;
        }

        $this->warn('Sesi WAHA bermasalah: ' . $hasil['status']);

        /*
         * Tetap SUCCESS. Sesi putus adalah keadaan yang sudah ditangani —
         * antrean menahan, Telegram sudah dikabari. Menjadikannya gagal hanya
         * membuat penjadwal ikut berisik tanpa menambah satu pun tindakan.
         */
        return self::SUCCESS;
    }
}
