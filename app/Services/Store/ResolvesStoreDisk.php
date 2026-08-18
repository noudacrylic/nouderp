<?php

namespace App\Services\Store;

use App\Models\R2Setting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Pemilih disk media etalase: R2 bila kredensialnya aktif (Settings → Integrasi →
 * Cloudflare R2), selain itu disk lokal 'public'.
 *
 * Logika ini semula disalin di StoreMediaService dan ArticleMediaService. Salinan
 * ketiga tidak dibuat lagi — layanan media baru memakai trait ini.
 */
trait ResolvesStoreDisk
{
    private ?Filesystem $resolvedDisk = null;

    private function fs(): Filesystem
    {
        if ($this->resolvedDisk) {
            return $this->resolvedDisk;
        }

        $r2 = R2Setting::current();
        if ($r2 && $r2->isConfigured()) {
            return $this->resolvedDisk = Storage::build($r2->diskConfig());
        }

        return $this->resolvedDisk = Storage::disk(config('store.local_disk', 'public'));
    }
}
