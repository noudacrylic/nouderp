<?php

namespace App\Console\Commands;

use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Services\CrmMediaStore;
use Illuminate\Console\Command;

/**
 * Penyapu masa simpan lampiran chat.
 *
 * Yang disapu HANYA lampiran di percakapan yang tak tertaut apa pun di ERP —
 * lead yang tidak jadi, basa-basi, tangkapan layar yang sudah selesai dipakai.
 * Itu justru bagian yang paling banyak jumlahnya.
 *
 * Yang percakapannya sudah tertaut pelanggan atau punya notifikasi pesanan
 * tidak pernah ikut tersapu: di situlah logo instansi yang sudah tercetak
 * berada. Berkas itu tidak bisa diambil lagi dari mana pun setelah ~30 hari,
 * dan pelanggan yang memesan ulang tahun depan mengharapkan kita masih punya.
 *
 * Barisnya TIDAK dihapus — hanya berkasnya. Di thread nanti muncul "lampiran
 * dihapus otomatis" beserta tanggalnya, bukan gambar rusak tanpa keterangan.
 */
class CrmGcLampiran extends Command
{
    protected $signature = 'crm:gc-lampiran
                            {--days= : Override masa simpan (default config crm.attachment_retention_days)}
                            {--dry-run : Tampilkan saja apa yang akan disapu, jangan hapus}';

    protected $description = 'Hapus berkas lampiran chat lama yang tidak tertaut dokumen ERP';

    public function handle(CrmMediaStore $media): int
    {
        $hari = (int) ($this->option('days') ?? config('crm.attachment_retention_days', 180));

        if ($hari < 30) {
            $this->error("Masa simpan {$hari} hari terlalu pendek — media Meta saja bertahan ~30 hari.");

            return self::FAILURE;
        }

        $calon = CrmAttachment::bisaDisapu($hari)->orderBy('id')->get();

        if ($calon->isEmpty()) {
            $this->info("Tidak ada lampiran yang lewat {$hari} hari dan tidak tertaut dokumen.");

            return self::SUCCESS;
        }

        $bytes = (int) $calon->sum('size_bytes');

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                'Uji coba: %d lampiran (%s) AKAN disapu — tidak ada yang dihapus.',
                $calon->count(),
                $this->ukuran($bytes)
            ));

            return self::SUCCESS;
        }

        $calon->each(fn (CrmAttachment $l) => $media->buangBerkas($l));

        $this->info(sprintf(
            '%d lampiran disapu (%s dibebaskan), masa simpan %d hari.',
            $calon->count(),
            $this->ukuran($bytes),
            $hari
        ));

        return self::SUCCESS;
    }

    private function ukuran(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
