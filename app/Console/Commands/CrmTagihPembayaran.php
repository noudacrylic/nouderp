<?php

namespace App\Console\Commands;

use App\Modules\CRM\Services\BillingReminderService;
use Illuminate\Console\Command;

/**
 * Satu putaran penagihan pesanan yang tautan bayarnya belum dibayar.
 *
 * Dijadwalkan SEKALI SEHARI, bukan tiap jam: jadwalnya berbasis hari penuh,
 * jadi jalan berulang dalam sehari tidak menghasilkan apa pun selain risiko —
 * dan pembatalan pesanan bukan hal yang pantas dicoba berkali-kali sehari.
 *
 * `--dry-run` memperlihatkan yang AKAN terjadi tanpa mengirim atau
 * membatalkan apa pun. Wajib ada: putaran pertama di server sungguhan bisa
 * saja menemukan puluhan pesanan lama yang menggantung, dan membatalkannya
 * serentak tanpa sempat dilihat manusia adalah kerusakan yang tak bisa
 * ditarik kembali.
 */
class CrmTagihPembayaran extends Command
{
    protected $signature = 'crm:tagih-pembayaran {--dry-run : Tampilkan saja, jangan kirim/batalkan}';
    protected $description = 'Tagih pesanan yang tautan bayarnya belum dibayar; batalkan yang lewat batas';

    public function handle(BillingReminderService $tagihan): int
    {
        $jadwal = implode(', ', $tagihan->jadwal());
        $this->line("Jadwal tagih: hari ke-{$jadwal}. Batal otomatis: hari ke-{$tagihan->batasHari()}.");

        if ($this->option('dry-run')) {
            $rencana = $tagihan->rencana();

            if (empty($rencana)) {
                $this->info('Tidak ada pesanan yang perlu ditagih maupun dibatalkan.');

                return self::SUCCESS;
            }

            $this->table(['Pesanan', 'Umur (hari)', 'Tindakan', 'Keterangan'], $rencana);
            $this->warn('Uji coba — tidak ada yang dikirim maupun dibatalkan.');

            return self::SUCCESS;
        }

        $h = $tagihan->jalankan();

        $this->info(
            "Penagihan: {$h['diperiksa']} diperiksa, {$h['ditagih']} tagihan diantrekan, "
            . "{$h['dibatalkan']} pesanan dibatalkan, {$h['dilewati']} dilewati."
        );

        return self::SUCCESS;
    }
}
