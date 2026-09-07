<?php

namespace App\Console\Commands;

use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmWebhookEvent;
use Illuminate\Console\Command;

/**
 * Isi `wam_id` pesan KELUAR dari peristiwa webhook yang sudah tersimpan.
 *
 * Sampai 7 September 2026 wamid pesan keluar dibuang begitu saja: peristiwa
 * 'delivered'/'read' cuma dipakai menaikkan centang, padahal ia satu-satunya
 * pembawa wamid untuk pesan kita sendiri (jawaban vendor saat mengirim hanya
 * membawa id internalnya).
 *
 * Akibatnya pesan lama tidak bisa dikutip — dan kalau dipaksa, kutipannya
 * diabaikan diam-diam oleh API. Untungnya seluruh payload webhook masih
 * tersimpan utuh, jadi wamid-nya bisa diambil kembali dari sana.
 *
 * Aman diulang: hanya mengisi baris yang `wam_id`-nya masih kosong.
 */
class CrmIsiWamid extends Command
{
    protected $signature = 'crm:isi-wamid {--dry-run : Tampilkan saja, jangan ubah apa pun}';

    protected $description = 'Isi wam_id pesan keluar dari payload webhook delivered/read yang tersimpan';

    public function handle(): int
    {
        $kering = (bool) $this->option('dry-run');

        /*
         * Dibaca dari yang TERBARU supaya bila satu pesan punya beberapa
         * peristiwa (sent → delivered → read), yang tercatat pertama kali
         * adalah yang paling akhir. Nilainya sama, tapi urutannya membuat
         * hasilnya tidak bergantung pada kebetulan.
         */
        $wamid = [];

        CrmWebhookEvent::query()
            ->whereIn('event_type', ['message.delivered', 'message.read', 'message.failed'])
            ->orderByDesc('id')
            ->chunk(200, function ($peristiwa) use (&$wamid) {
                foreach ($peristiwa as $e) {
                    $data = (array) data_get($e->payload, 'data', []);
                    $id   = data_get($data, 'message_id');
                    $wam  = data_get($data, 'raw.id');

                    if (is_string($id) && is_string($wam) && str_starts_with($wam, 'wamid.') && ! isset($wamid[$id])) {
                        $wamid[$id] = $wam;
                    }
                }
            });

        if (! $wamid) {
            $this->info('Tidak ada wamid yang bisa dipungut dari riwayat webhook.');

            return self::SUCCESS;
        }

        $terisi = 0;

        foreach (array_chunk($wamid, 100, true) as $bagian) {
            $pesan = CrmMessage::whereNull('wam_id')
                ->whereIn('provider_message_id', array_keys($bagian))
                ->get();

            foreach ($pesan as $m) {
                $this->line('  ' . $m->id . ' → ' . $bagian[$m->provider_message_id]);

                if (! $kering) {
                    $m->forceFill(['wam_id' => $bagian[$m->provider_message_id]])->save();
                }

                $terisi++;
            }
        }

        $this->info(($kering ? '[uji coba] ' : '') . $terisi . ' pesan mendapat wamid-nya kembali.');

        return self::SUCCESS;
    }
}
