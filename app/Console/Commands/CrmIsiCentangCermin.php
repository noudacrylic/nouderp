<?php

namespace App\Console\Commands;

use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Services\WahaCerminService;
use Illuminate\Console\Command;

/**
 * Isi centang pesan cermin yang terlanjur direkam tanpa status — SEKALI JALAN.
 *
 * Tidak memanggil WAHA sama sekali. Tingkat ack sudah ikut di payload
 * `message.any` sejak hari pertama dan tersimpan apa adanya di kolom `raw`;
 * yang dulu hilang hanyalah langkah memetakannya ke kolom `status`. Jadi ini
 * pekerjaan membaca ulang apa yang sudah kita punya, bukan menarik data baru —
 * dan itu sebabnya ia aman dijalankan kapan pun, termasuk saat WAHA mati.
 *
 * AMAN DIULANG. Yang disentuh hanya pesan KELUAR di thread cermin yang kolom
 * `status`-nya MASIH KOSONG. Pesan yang sudah punya centang tidak pernah
 * ditulis ulang — `raw` memegang ack pada saat perekaman, jadi menimpanya akan
 * menurunkan centang biru yang sudah telanjur diperbarui `message.ack` kembali
 * menjadi abu-abu.
 */
class CrmIsiCentangCermin extends Command
{
    protected $signature = 'crm:isi-centang-cermin
                            {--limit=0 : Maksimum pesan diperiksa (0 = semua)}
                            {--dry-run : Hitung saja, jangan tulis apa pun}';

    protected $description = 'Isi centang pesan cermin dari tingkat ack yang sudah tersimpan di kolom raw';

    public function handle(): int
    {
        $kering = (bool) $this->option('dry-run');

        $percakapan = CrmConversation::where('channel', CrmConversation::KANAL_CERMIN)->pluck('id');

        if ($percakapan->isEmpty()) {
            $this->info('Belum ada percakapan cermin.');

            return self::SUCCESS;
        }

        $query = CrmMessage::whereIn('conversation_id', $percakapan)
            ->where('direction', CrmMessage::KELUAR)
            ->whereNull('status')
            ->orderBy('id');

        // Sengaja TIDAK lewat ->limit(): chunkById memasang batasnya sendiri
        // per potongan, jadi ->limit() di sini akan terbaca seperti berlaku
        // padahal diam-diam diabaikan. Batasnya dihitung di dalam perulangan.
        $limit = (int) $this->option('limit');

        $diisi = 0;
        $lewat = 0;

        /*
         * Dipotong per 500 baris: cermin berisi ribuan pesan keluar dan
         * memuat semuanya sekaligus akan menghabiskan memori CLI di server
         * yang sama dengan MariaDB.
         */
        $query->chunkById(500, function ($pesan) use (&$diisi, &$lewat, $kering, $limit) {
            foreach ($pesan as $m) {
                if ($limit > 0 && ($diisi + $lewat) >= $limit) {
                    return false;
                }

                $status = WahaCerminService::statusDariAck((array) $m->raw);

                if ($status === null || $status === $m->status) {
                    $lewat++;

                    continue;
                }

                if (! $kering) {
                    $m->forceFill(['status' => $status])->save();
                }

                $diisi++;
            }
        });

        $this->info(($kering ? '[UJI COBA] ' : '') . "Centang: {$diisi} diisi, {$lewat} dilewati.");

        if ($lewat > 0) {
            $this->line('Yang dilewati sudah benar statusnya, atau payload-nya memang tak membawa ack.');
        }

        return self::SUCCESS;
    }
}
