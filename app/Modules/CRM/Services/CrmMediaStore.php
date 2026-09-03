<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmAttachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mengunduh lampiran masuk ke penyimpanan sendiri.
 *
 * Ini bagian yang tidak boleh gagal diam-diam: Meta membuang salinan medianya
 * setelah ~30 hari, dan `media_id` vendor juga kedaluwarsa 30 hari. Lewat dari
 * itu, logo yang dikirim pelanggan tidak bisa diambil lagi dari mana pun —
 * satu-satunya jalan adalah meminta ulang ke pelanggannya.
 *
 * Karena itu kegagalan SELALU meninggalkan `download_error` yang terbaca, bukan
 * sekadar baris yang diam tanpa berkas.
 */
class CrmMediaStore
{
    /** @return bool true bila berkas berhasil tersimpan. */
    public function unduh(CrmAttachment $lampiran): bool
    {
        if ($lampiran->tersimpanAman()) {
            return true;
        }

        $url = $lampiran->source_url;

        if (blank($url)) {
            // Hanya punya media_id: butuh endpoint unduh milik vendor, yang
            // belum bisa dipastikan sebelum akunnya ada. Ditandai supaya
            // terlihat, bukan menggantung sebagai "belum terunduh" selamanya.
            $lampiran->forceFill([
                'download_error' => 'Lampiran hanya membawa media_id tanpa URL — belum bisa diunduh.',
            ])->save();

            return false;
        }

        $batas = (int) config('crm.max_media_bytes', 25 * 1024 * 1024);
        $temp  = tempnam(sys_get_temp_dir(), 'crm-media-');

        try {
            $res = Http::timeout(60)->sink($temp)->get($url);

            if ($res->failed()) {
                return $this->gagal($lampiran, 'Unduhan ditolak sumber: HTTP ' . $res->status(), $temp);
            }

            $ukuran = @filesize($temp) ?: 0;

            if ($ukuran === 0) {
                return $this->gagal($lampiran, 'Berkas yang diunduh kosong.', $temp);
            }

            // Rem ukuran diperiksa SESUDAH unduhan karena banyak sumber tidak
            // mengirim Content-Length. Yang dijaga adalah isi disk, bukan
            // bandwidth — dan berkas kelewat besar tidak pernah ikut tersimpan.
            if ($ukuran > $batas) {
                return $this->gagal(
                    $lampiran,
                    sprintf('Berkas %s melebihi batas %s.', $this->ukuran($ukuran), $this->ukuran($batas)),
                    $temp
                );
            }

            $mime = $lampiran->mime ?: ($res->header('Content-Type') ?: null);
            $path = $this->path($lampiran, $mime);
            $disk = (string) config('crm.media_disk', 'local');

            $stream = fopen($temp, 'r');
            $ok     = Storage::disk($disk)->put($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $ok) {
                return $this->gagal($lampiran, 'Gagal menulis berkas ke disk ' . $disk . '.', $temp);
            }

            $lampiran->forceFill([
                'disk'           => $disk,
                'path'           => $path,
                'mime'           => $mime,
                'size_bytes'     => $ukuran,
                'downloaded_at'  => now(),
                'download_error' => null,
            ])->save();

            return true;
        } catch (\Throwable $e) {
            Log::warning('[CRM] gagal mengunduh lampiran', ['attachment_id' => $lampiran->id, 'error' => $e->getMessage()]);

            return $this->gagal($lampiran, $e->getMessage(), $temp);
        } finally {
            if (is_string($temp) && file_exists($temp)) {
                @unlink($temp);
            }
        }
    }

    /** Hapus berkasnya dari disk, barisnya tetap tinggal sebagai jejak. */
    public function buangBerkas(CrmAttachment $lampiran): bool
    {
        if ($lampiran->path && $lampiran->disk && Storage::disk($lampiran->disk)->exists($lampiran->path)) {
            Storage::disk($lampiran->disk)->delete($lampiran->path);
        }

        $lampiran->forceFill([
            'path'          => null,
            'disk'          => null,
            'downloaded_at' => null,
            'purged_at'     => now(),
        ])->save();

        return true;
    }

    /**
     * Nama berkas dibentuk dari id lampiran, BUKAN dari nama kiriman pelanggan.
     * Nama kiriman ikut disimpan di kolomnya sendiri untuk ditampilkan; memakainya
     * sebagai path membuka jalan tabrakan nama dan '../' dari pihak luar.
     */
    private function path(CrmAttachment $lampiran, ?string $mime): string
    {
        $ext = $this->ekstensi($lampiran->original_name, $mime);
        $dir = trim((string) config('crm.media_path', 'crm/lampiran'), '/');

        return sprintf('%s/%s/%d%s', $dir, now()->format('Y/m'), $lampiran->id, $ext);
    }

    private function ekstensi(?string $nama, ?string $mime): string
    {
        if ($nama && ($ext = pathinfo($nama, PATHINFO_EXTENSION))) {
            return '.' . Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $ext));
        }

        return match (Str::before((string) $mime, ';')) {
            'image/jpeg'      => '.jpg',
            'image/png'       => '.png',
            'image/webp'      => '.webp',
            'application/pdf' => '.pdf',
            'video/mp4'       => '.mp4',
            'audio/ogg'       => '.ogg',
            default           => '',
        };
    }

    private function gagal(CrmAttachment $lampiran, string $pesan, ?string $temp): bool
    {
        $lampiran->forceFill(['download_error' => $pesan])->save();

        if (is_string($temp) && file_exists($temp)) {
            @unlink($temp);
        }

        return false;
    }

    private function ukuran(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
