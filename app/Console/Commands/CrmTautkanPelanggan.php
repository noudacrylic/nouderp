<?php

namespace App\Console\Commands;

use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\IncomingWebhookService;
use Illuminate\Console\Command;

/**
 * Tautkan ulang chat Lead ke master pelanggan — SEKALI JALAN untuk data lama.
 *
 * Sejak ada CrmCustomerObserver & pencocokan saat thread dibuka, chat baru
 * tertaut sendiri. Perintah ini membereskan yang terlanjur tertinggal sebagai
 * Lead padahal nomornya sudah ada di master. Aturan cocoknya dipinjam dari
 * webhook (ekor 9 digit, nomor cabang, nomor notifikasi tambahan).
 *
 * AMAN DIULANG: hanya percakapan yang customer_id-nya masih kosong.
 */
class CrmTautkanPelanggan extends Command
{
    protected $signature = 'crm:tautkan-pelanggan {--dry-run : Tampilkan saja, jangan tulis apa pun}';

    protected $description = 'Tautkan chat CRM yang masih Lead ke pelanggan yang nomornya sudah ada di master';

    public function handle(IncomingWebhookService $webhook): int
    {
        $kering = (bool) $this->option('dry-run');
        $jumlah = 0;

        CrmConversation::query()
            ->whereNull('customer_id')
            ->where('channel', 'like', 'whatsapp%')
            ->orderBy('id')
            ->each(function (CrmConversation $p) use ($webhook, $kering, &$jumlah) {
                $customer = $webhook->cocokkanPelanggan($p->contact_key);

                if (! $customer) {
                    return;
                }

                $jumlah++;
                $this->line("#{$p->id} {$p->contact_key} → {$customer->name} (#{$customer->id})");

                if (! $kering) {
                    $p->forceFill(['customer_id' => $customer->id])->save();
                }
            });

        $this->info(($kering ? '[dry-run] ' : '') . "{$jumlah} chat ditautkan.");

        return self::SUCCESS;
    }
}
