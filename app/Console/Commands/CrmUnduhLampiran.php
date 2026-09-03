<?php

namespace App\Console\Commands;

use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Services\CrmMediaStore;
use Illuminate\Console\Command;

/**
 * Unduh lampiran masuk yang belum tersimpan di penyimpanan sendiri.
 *
 * Sengaja terpisah dari permintaan webhook: mengunduh di sana membuat vendor
 * menunggu, dan vendor yang menunggu terlalu lama akan mengulang kiriman lalu
 * MEMATIKAN endpoint setelah gagal beruntun. Jeda beberapa detik di sini tidak
 * berarti apa-apa dibanding jendela ~30 hari milik Meta.
 *
 * `--ulangi-gagal` untuk mencoba lagi yang pernah gagal (mis. jaringan sempat
 * putus) — tidak otomatis, supaya URL yang memang sudah mati tidak dicoba
 * berulang tanpa henti.
 */
class CrmUnduhLampiran extends Command
{
    protected $signature = 'crm:unduh-lampiran
                            {--limit=100 : Maksimum lampiran per jalan}
                            {--ulangi-gagal : Coba lagi lampiran yang sebelumnya gagal}';

    protected $description = 'Unduh lampiran chat masuk ke penyimpanan sendiri (Meta membuangnya setelah ~30 hari)';

    public function handle(CrmMediaStore $media): int
    {
        $query = $this->option('ulangi-gagal')
            ? CrmAttachment::whereNull('downloaded_at')->whereNull('purged_at')->whereNotNull('source_url')
            : CrmAttachment::belumTerunduh();

        $antrean = $query->orderBy('id')->limit((int) $this->option('limit'))->get();

        if ($antrean->isEmpty()) {
            $this->info('Tidak ada lampiran yang menunggu diunduh.');

            return self::SUCCESS;
        }

        $berhasil = 0;

        foreach ($antrean as $lampiran) {
            if ($this->option('ulangi-gagal')) {
                $lampiran->forceFill(['download_error' => null])->save();
            }

            $media->unduh($lampiran) ? $berhasil++ : null;
        }

        $gagal = $antrean->count() - $berhasil;

        $this->info("Lampiran: {$berhasil} tersimpan, {$gagal} gagal (dari {$antrean->count()} dicoba).");

        if ($gagal > 0) {
            $this->warn('Yang gagal punya keterangan di kolom download_error — periksa sebelum 30 hari berlalu.');
        }

        return self::SUCCESS;
    }
}
