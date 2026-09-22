<?php

namespace App\Console\Commands;

use App\Modules\CRM\Services\WahaHealthService;
use App\Modules\CRM\Support\PeranWaha;
use Illuminate\Console\Command;

/**
 * Denyut jantung sesi WhatsApp tak resmi (WAHA), tiap 5 menit.
 *
 * Perlu terjadwal, bukan diperiksa saat mengirim saja: antara dua pesanan bisa
 * lewat berjam-jam, dan selama itu tak ada seorang pun yang tahu sesinya sudah
 * putus. Yang ingin dicegah adalah kabar pelanggan berhenti diam-diam
 * sepanjang akhir pekan.
 *
 * Memeriksa SEMUA peran (nomor utama & nomor notifikasi), karena satu
 * container WAHA kini memegang lebih dari satu nomor dan keduanya putus
 * sendiri-sendiri.
 *
 * Fitur otomatis wajib punya pemicu tangan juga — di sini pemicunya ada dua:
 * tombol "Uji Sesi" di layar Pengaturan WAHA (per nomor), dan opsi --peran di
 * perintah ini.
 */
class CrmPantauWaha extends Command
{
    protected $signature = 'crm:pantau-waha {--peran= : Periksa satu peran saja (utama|notifikasi)}';
    protected $description = 'Periksa sesi WAHA tiap nomor; peringatkan lewat Telegram (beserta QR) bila putus';

    public function handle(WahaHealthService $kesehatan): int
    {
        $peran = $this->option('peran');

        if ($peran !== null && ! PeranWaha::sah($peran)) {
            $this->error('Peran tidak dikenal: ' . $peran . '. Pilih: ' . implode(', ', PeranWaha::SEMUA));

            return self::INVALID;
        }

        $hasil = $peran !== null
            ? [$peran => $kesehatan->periksa($peran)]
            : $kesehatan->periksaSemua();

        foreach ($hasil as $nama => $keadaan) {
            $label = PeranWaha::label($nama);

            if ($keadaan['dilewati']) {
                $this->line($label . ': tidak dipakai — tidak ada yang dipantau.');

                continue;
            }

            if ($keadaan['siap']) {
                $this->info($label . ': sehat (WORKING).');

                continue;
            }

            $this->warn($label . ': bermasalah — ' . $keadaan['status']);
        }

        /*
         * Tetap SUCCESS. Sesi putus adalah keadaan yang sudah ditangani —
         * antrean menahan, Telegram sudah dikabari. Menjadikannya gagal hanya
         * membuat penjadwal ikut berisik tanpa menambah satu pun tindakan.
         */
        return self::SUCCESS;
    }
}
