<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmMessage;
use Illuminate\Http\UploadedFile;
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
     * Simpan berkas yang DIUNGGAH admin (tempel tangkapan layar, pilih berkas)
     * sebagai lampiran pesan keluar.
     *
     * Disimpan di disk kita sendiri persis seperti lampiran masuk, dan itu
     * disengaja: `media_id` vendor kedaluwarsa 30 hari, sedangkan revisi desain
     * yang dikirim ke pelanggan justru yang paling sering dicari lagi berbulan
     * kemudian ("yang kemarin itu versi mana?").
     */
    public function simpanUnggahan(CrmMessage $pesan, UploadedFile $berkas, ?string $mediaId = null): CrmAttachment
    {
        $lampiran = CrmAttachment::create([
            'message_id'        => $pesan->id,
            'original_name'     => $berkas->getClientOriginalName(),
            'mime'              => $berkas->getClientMimeType(),
            'size_bytes'        => $berkas->getSize(),
            'provider_media_id' => $mediaId,
        ]);

        $disk = (string) config('crm.media_disk', 'local');
        $path = $this->path($lampiran, $berkas->getClientMimeType());

        Storage::disk($disk)->putFileAs(dirname($path), $berkas, basename($path));

        $lampiran->forceFill([
            'disk'          => $disk,
            'path'          => $path,
            'downloaded_at' => now(),
        ])->save();

        return $lampiran;
    }

    /**
     * Tarik sebuah gambar dari URL publik (foto etalase di R2) dan jadikan
     * lampiran KELUAR milik sebuah pesan.
     *
     * Kenapa disalin, bukan cukup menyerahkan URL R2-nya ke vendor: gelembung
     * di thread membaca berkas dari disk kita. Kalau lampirannya cuma menunjuk
     * alamat luar, admin mengirim foto yang tak pernah bisa ia lihat lagi di
     * riwayat — dan foto etalase memang berganti tiap kali produknya difoto
     * ulang, jadi yang tersimpan di chat harus yang BENAR-BENAR terkirim.
     *
     * Null bila gambarnya tak bisa diambil; pemanggilnya yang memutuskan
     * apakah itu berarti batal kirim.
     */
    public function simpanDariUrl(CrmMessage $pesan, string $url, ?string $namaAsli = null): ?CrmAttachment
    {
        try {
            $res = Http::timeout(20)->get($url);
        } catch (\Throwable $e) {
            Log::warning('CRM: gambar produk gagal diambil', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }

        if (!$res->successful() || $res->body() === '') {
            Log::warning('CRM: gambar produk gagal diambil', ['url' => $url, 'status' => $res->status()]);
            return null;
        }

        $mime = Str::before((string) $res->header('Content-Type'), ';') ?: 'image/jpeg';
        if (!Str::startsWith($mime, 'image/')) {
            Log::warning('CRM: alamat foto produk bukan gambar', ['url' => $url, 'mime' => $mime]);
            return null;
        }

        $lampiran = CrmAttachment::create([
            'message_id'    => $pesan->id,
            'original_name' => $namaAsli ?: basename(parse_url($url, PHP_URL_PATH) ?: 'foto'),
            'mime'          => $mime,
            'size_bytes'    => strlen($res->body()),
            'source_url'    => $url,
        ]);

        $disk = (string) config('crm.media_disk', 'local');
        $path = $this->path($lampiran, $mime);

        Storage::disk($disk)->put($path, $res->body());

        $lampiran->forceFill([
            'disk'          => $disk,
            'path'          => $path,
            'downloaded_at' => now(),
        ])->save();

        return $lampiran;
    }

    /**
     * Salin lampiran ke pesan lain — dipakai saat meneruskan.
     *
     * Berkasnya benar-benar DIGANDAKAN, bukan sekadar ditunjuk ulang, karena
     * dua alasan yang sama-sama mengunci:
     *
     *  1. Rute media publik (satu-satunya alamat yang bisa diambil Meta) hanya
     *     mau menyajikan lampiran pesan KELUAR. Meneruskan foto kiriman
     *     pelanggan berarti berkasnya harus punya baris keluar sendiri, kalau
     *     tidak vendor menerima URL yang menjawab 404.
     *  2. Penyapu lampiran membuang berkas lama menurut umur percakapan
     *     ASALNYA. Kalau yang diteruskan cuma menumpang berkas yang sama, chat
     *     tujuan kehilangan gambarnya saat chat asal disapu — padahal di sana
     *     ia baru saja dikirim.
     *
     * Null bila berkas sumbernya memang sudah tidak ada di disk.
     */
    public function salin(CrmMessage $tujuan, CrmAttachment $sumber): ?CrmAttachment
    {
        if (! $sumber->tersimpanAman() || ! Storage::disk($sumber->disk)->exists($sumber->path)) {
            return null;
        }

        $lampiran = CrmAttachment::create([
            'message_id'    => $tujuan->id,
            'original_name' => $sumber->original_name,
            'mime'          => $sumber->mime,
            'size_bytes'    => $sumber->size_bytes,
        ]);

        $disk = (string) config('crm.media_disk', 'local');
        $path = $this->path($lampiran, $sumber->mime);

        // Salinan lintas disk tetap harus jalan: sumbernya bisa duduk di disk
        // lama kalau config media_disk pernah diganti.
        $ok = Storage::disk($disk)->put($path, Storage::disk($sumber->disk)->get($sumber->path));

        if (! $ok) {
            $lampiran->delete();

            return null;
        }

        $lampiran->forceFill([
            'disk'          => $disk,
            'path'          => $path,
            'downloaded_at' => now(),
        ])->save();

        return $lampiran;
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
