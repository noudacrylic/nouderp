<?php

namespace App\Services\Store;

use App\Models\R2Setting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Upload gambar artikel blog (cover + gambar inline editor) ke disk media yang sama
 * dengan Produk Store: R2 bila aktif, else lokal 'public'. Nama file SEO-friendly +
 * akhiran unik (versioned) → ganti gambar = URL baru, tak perlu purge CDN.
 */
class ArticleMediaService
{
    private ?Filesystem $fs = null;

    private function fs(): Filesystem
    {
        if ($this->fs) return $this->fs;

        $r2 = R2Setting::current();
        if ($r2 && $r2->isConfigured()) {
            return $this->fs = Storage::build($r2->diskConfig());
        }

        return $this->fs = Storage::disk(config('store.local_disk', 'public'));
    }

    /** Simpan gambar; kembalikan ['url' => publik, 'key' => path disk]. */
    public function upload(UploadedFile $file, ?string $slugHint = null): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $base = Str::slug($slugHint ?: 'artikel') ?: 'artikel';
        $name = "{$base}-" . strtolower(Str::random(6)) . ($ext ? ".$ext" : '');
        $dir = trim(config('store.article_path', 'store/articles'), '/');

        $key = $this->fs()->putFileAs($dir, $file, $name, 'public');

        return ['url' => $this->fs()->url($key), 'key' => $key];
    }

    /** Hapus file di disk bila key ada (best-effort). */
    public function delete(?string $key): void
    {
        if ($key && $this->fs()->exists($key)) {
            $this->fs()->delete($key);
        }
    }
}
