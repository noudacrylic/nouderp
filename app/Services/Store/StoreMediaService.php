<?php

namespace App\Services\Store;

use App\Models\R2Setting;
use App\Models\StoreProduct;
use App\Models\StoreProductMedia;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pengelola media etalase (foto & video Produk Store).
 *
 * Disk-agnostic via config('store.media_disk') — default 'public' (lokal),
 * tinggal set STORE_MEDIA_DISK=r2 saat Cloudflare R2 siap.
 *
 * Nama file UNIK per upload (versioned uuid) → ganti foto = URL baru → tak perlu
 * purge CDN. Hapus = soft delete (file tetap), dibersihkan store:gc-media setelah
 * jeda (config store.media_gc_days) agar tak ada gambar rusak saat propagasi cache.
 */
class StoreMediaService
{
    private ?Filesystem $fs = null;

    /**
     * Disk media: bila R2 aktif (Settings → Integrasi → Cloudflare R2) bangun
     * disk S3 dari kredensial DB; bila tidak, jatuh ke disk lokal 'public'.
     */
    private function fs(): Filesystem
    {
        if ($this->fs) return $this->fs;

        $r2 = R2Setting::current();
        if ($r2 && $r2->isConfigured()) {
            return $this->fs = Storage::build($r2->diskConfig());
        }

        return $this->fs = Storage::disk(config('store.local_disk', 'public'));
    }

    public function uploadImage(StoreProduct $product, UploadedFile $file): StoreProductMedia
    {
        $key = $this->store($product, $file);

        return $product->media()->create([
            'kind'       => 'image',
            'source'     => 'r2',
            'url'        => $this->fs()->url($key),
            'r2_key'     => $key,
            'is_primary' => !$product->media()->where('kind', 'image')->exists(), // foto pertama jadi utama
            'sort_order' => $this->nextSortOrder($product),
        ]);
    }

    public function uploadVideo(StoreProduct $product, UploadedFile $file): StoreProductMedia
    {
        $key = $this->store($product, $file);

        return $product->media()->create([
            'kind'       => 'video',
            'source'     => 'r2',
            'url'        => $this->fs()->url($key),
            'r2_key'     => $key,
            'sort_order' => $this->nextSortOrder($product),
        ]);
    }

    public function addYoutube(StoreProduct $product, string $url): StoreProductMedia
    {
        return $product->media()->create([
            'kind'       => 'video',
            'source'     => 'youtube',
            'url'        => $url,
            'r2_key'     => null,
            'sort_order' => $this->nextSortOrder($product),
        ]);
    }

    /** Soft delete — file dibiarkan, dibersihkan store:gc-media setelah jeda. */
    public function delete(StoreProductMedia $media): void
    {
        $wasPrimary = $media->is_primary && $media->kind === 'image';
        $product = $media->storeProduct;
        $media->delete();

        // Bila foto utama dihapus, promosikan foto tersisa pertama.
        if ($wasPrimary && $product) {
            $next = $product->media()->where('kind', 'image')->orderBy('sort_order')->first();
            if ($next) $next->update(['is_primary' => true]);
        }
    }

    public function setPrimary(StoreProduct $product, int $mediaId): void
    {
        $product->media()->where('kind', 'image')->update(['is_primary' => false]);
        $product->media()->where('id', $mediaId)->where('kind', 'image')->update(['is_primary' => true]);
    }

    /** $orderedIds = array id media sesuai urutan baru. */
    public function reorder(StoreProduct $product, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $i => $id) {
            $product->media()->where('id', (int) $id)->update(['sort_order' => $i]);
        }
    }

    /**
     * Garbage collector: hapus file di disk untuk media soft-deleted yang lebih
     * tua dari $graceDays, lalu forceDelete barisnya. Return jumlah file dihapus.
     */
    public function gc(int $graceDays): int
    {
        $cutoff = now()->subDays($graceDays);
        $count = 0;

        StoreProductMedia::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->whereNotNull('r2_key')
            ->chunkById(200, function ($rows) use (&$count) {
                foreach ($rows as $m) {
                    if ($m->r2_key && $this->fs()->exists($m->r2_key)) {
                        $this->fs()->delete($m->r2_key);
                    }
                    $m->forceDelete();
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Hapus SEMUA file media milik produk (termasuk yang soft-deleted) dari disk.
     * Dipanggil saat Produk Store dihapus permanen (baris media ikut cascade FK).
     */
    public function purgeFilesFor(StoreProduct $product): void
    {
        $keys = StoreProductMedia::withTrashed()
            ->where('store_product_id', $product->id)
            ->whereNotNull('r2_key')
            ->pluck('r2_key')
            ->all();

        foreach ($keys as $key) {
            if ($this->fs()->exists($key)) {
                $this->fs()->delete($key);
            }
        }
    }

    private function store(StoreProduct $product, UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        // Nama file SEO-friendly: slug produk (kata kunci) + akhiran pendek unik.
        // Tetap unik (versioned) supaya ganti foto = URL baru → tak perlu purge CDN.
        $base = Str::slug($product->slug ?: $product->name ?: 'foto') ?: 'foto';
        $suffix = strtolower(Str::random(6));
        $name = "{$base}-{$suffix}" . ($ext ? ".$ext" : '');

        $dir = trim(config('store.media_path', 'store/products'), '/') . '/' . $product->id;

        // putFileAs → simpan dengan nama tsb, visibility public.
        return $this->fs()->putFileAs($dir, $file, $name, 'public');
    }

    private function nextSortOrder(StoreProduct $product): int
    {
        return (int) $product->media()->max('sort_order') + 1;
    }
}
