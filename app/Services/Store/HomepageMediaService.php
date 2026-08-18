<?php

namespace App\Services\Store;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Gambar beranda etalase (foto hero & gambar bagikan/OG). Cuma dua berkas, tapi
 * keduanya diganti-ganti: nama file diberi akhiran acak supaya URL-nya ikut berubah
 * tiap unggah — gambar lama yang sudah tersimpan di cache CDN atau pratinjau
 * WhatsApp tidak akan menempel sebagai gambar "baru".
 */
class HomepageMediaService
{
    use ResolvesStoreDisk;

    /** Simpan gambar; kembalikan ['url' => publik, 'key' => path disk]. */
    public function upload(UploadedFile $file, string $nameHint): array
    {
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $base = Str::slug($nameHint) ?: 'beranda';
        $name = "{$base}-" . strtolower(Str::random(6)) . ($ext ? ".$ext" : '');
        $dir  = trim(config('store.homepage_path', 'store/homepage'), '/');

        $key = $this->fs()->putFileAs($dir, $file, $name, 'public');

        return ['url' => $this->fs()->url($key), 'key' => $key];
    }

    /** Hapus berkas bila key-nya ada (best-effort — gagal hapus tak boleh membatalkan simpan). */
    public function delete(?string $key): void
    {
        if (!$key) {
            return;
        }

        try {
            if ($this->fs()->exists($key)) {
                $this->fs()->delete($key);
            }
        } catch (\Throwable) {
            // Berkas yatim di disk jauh lebih ringan akibatnya daripada halaman
            // pengaturan yang menolak menyimpan karena R2 sedang tak bisa dihubungi.
        }
    }
}
