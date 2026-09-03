<?php

namespace App\Console\Commands;

use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Services\CrmOutboxSender;
use Illuminate\Console\Command;

/**
 * Kuras antrean notifikasi WhatsApp yang sudah jatuh tempo.
 *
 * Sengaja terjadwal, bukan dikirim langsung di dalam observer: pesan yang
 * ditunda ke jam buka ("siap diambil" yang jadi malam hari) baru berangkat di
 * sini, dan kegagalan jaringan tidak pernah menyentuh transaksi penjualan.
 *
 * Selama saklar `crm.dry_run` menyala, ChatManager menyerahkan driver palsu:
 * baris tetap ditandai terkirim, tapi tak sebutir paket pun keluar.
 */
class CrmKirimNotifikasi extends Command
{
    protected $signature = 'crm:kirim-notifikasi {--limit=50 : Maksimum baris per jalan}';
    protected $description = 'Kirim notifikasi pesanan (WhatsApp) yang sudah jatuh tempo di antrean CRM';

    public function handle(CrmOutboxSender $sender, ChatManager $chat): int
    {
        $menunggu = CrmOutboxMessage::jatuhTempo()->count();

        if ($menunggu === 0) {
            $this->info('Tidak ada notifikasi yang jatuh tempo.');

            return self::SUCCESS;
        }

        if ($chat->isDryRun()) {
            $this->warn('Saklar "jangan kirim" MENYALA — pesan dicatat, tidak dikirim sungguhan.');
        }

        $hasil = $sender->kirimYangJatuhTempo((int) $this->option('limit'));

        $this->info("Notifikasi: {$hasil['terkirim']} terkirim, {$hasil['gagal']} gagal (jatuh tempo {$menunggu}).");

        return self::SUCCESS;
    }
}
