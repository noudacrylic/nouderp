<?php

namespace App\Console\Commands;

use App\Modules\CRM\Services\NamaKontakService;
use Illuminate\Console\Command;

/**
 * Lengkapi nama profil WhatsApp percakapan yang masih tampil sebagai nomor.
 *
 * Sengaja terpisah dari permintaan webhook, alasannya sama dengan
 * crm:unduh-lampiran: vendor yang menunggu jawaban webhook terlalu lama akan
 * mengulang kiriman lalu mematikan endpoint. Nama yang muncul semenit
 * kemudian tidak merugikan siapa pun.
 */
class CrmAmbilNamaKontak extends Command
{
    protected $signature = 'crm:ambil-nama-kontak
                            {--limit=30 : Maksimum percakapan per jalan}';

    protected $description = 'Ambil nama profil WhatsApp dari penyedia chat untuk percakapan yang belum bernama';

    public function handle(NamaKontakService $nama): int
    {
        $hasil = $nama->lengkapiYangKosong((int) $this->option('limit'));

        if ($hasil['dicoba'] === 0) {
            $this->info('Tidak ada percakapan yang menunggu nama.');

            return self::SUCCESS;
        }

        $this->info("Nama kontak: {$hasil['dapat']} didapat, {$hasil['gagal']} gagal (dari {$hasil['dicoba']} ditanyakan).");

        return self::SUCCESS;
    }
}
