<?php

namespace App\Console\Commands;

use App\Services\Store\StoreMediaService;
use Illuminate\Console\Command;

/**
 * Garbage collector media etalase.
 *
 * Hapus file di disk untuk media Produk Store yang sudah soft-deleted dan lebih
 * tua dari jeda aman (config store.media_gc_days), lalu hapus permanen barisnya.
 * Jeda mencegah gambar rusak saat propagasi cache katalog.
 *
 * Dijadwalkan harian (lihat routes/console.php). Idempotent — aman diulang.
 */
class StoreGcMedia extends Command
{
    protected $signature = 'store:gc-media {--days= : Override jeda hari (default config store.media_gc_days)}';
    protected $description = 'Bersihkan file media Produk Store yang sudah dihapus & lewat masa jeda';

    public function handle(StoreMediaService $media): int
    {
        $days = (int) ($this->option('days') ?? config('store.media_gc_days', 7));
        $deleted = $media->gc($days);
        $this->info("GC media selesai: {$deleted} file dihapus (jeda {$days} hari).");
        return self::SUCCESS;
    }
}
