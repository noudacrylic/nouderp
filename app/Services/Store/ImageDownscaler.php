<?php

namespace App\Services\Store;

use Illuminate\Http\UploadedFile;

/**
 * Perkecil foto sebelum diunggah ke penyimpanan media.
 *
 * Sebelum ini tidak ada pengecilan sama sekali — berkas disimpan apa adanya.
 * Untuk artikel blog yang bergambar satu-dua itu masih lolos; untuk halaman
 * tutorial pemasangan tidak. Foto ponsel lazimnya 3-5 MB, dan satu tutorial
 * bisa memuat delapan foto langkah — sekitar 30 MB satu halaman. Yang membukanya
 * justru orang yang baru menempelkan QR di kotaknya: berdiri di kantor atau
 * toko, memakai kuota, sinyal seadanya. Halaman itu tak akan pernah selesai
 * dimuat.
 *
 * Dibatasi sisi panjang 1600 px: cukup tajam untuk dilihat penuh layar di
 * ponsel maupun laptop, dan menurunkan foto 4 MB ke kisaran 200 KB.
 *
 * Memakai GD bawaan PHP, bukan pustaka tambahan — sudah tersedia di server dan
 * kebutuhannya sesederhana ini.
 */
class ImageDownscaler
{
    /** Sisi terpanjang maksimum, dalam piksel. */
    private const MAX_EDGE = 1600;

    /** Mutu penyandian ulang. 82 = batas tempat mata sulit membedakan dari aslinya. */
    private const QUALITY = 82;

    /**
     * Kembalikan berkas yang sudah diperkecil, atau berkas asli bila tak perlu
     * / tak bisa diproses.
     *
     * Sengaja TIDAK pernah melempar galat: gagal memperkecil bukan alasan untuk
     * menggagalkan unggahan admin. Paling buruk, berkasnya tersimpan sebesar
     * aslinya — persis perilaku sebelum ini ada.
     */
    public function process(UploadedFile $file): UploadedFile
    {
        try {
            return $this->downscale($file) ?? $file;
        } catch (\Throwable) {
            return $file;
        }
    }

    private function downscale(UploadedFile $file): ?UploadedFile
    {
        $path = $file->getRealPath();
        if (!$path || !is_readable($path)) return null;

        $info = @getimagesize($path);
        if (!$info) return null;

        [$width, $height] = $info;
        $type = $info[2];

        // GIF dilewati: bisa beranimasi, dan GD hanya menyimpan bingkai pertama.
        $supported = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if (!in_array($type, $supported, true)) return null;

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        };
        if (!$src) return null;

        // Foto ponsel kerap tersimpan miring dengan penanda orientasi EXIF. Browser
        // menghormatinya, GD tidak — tanpa ini, gambar yang diperkecil jadi terputar.
        if ($type === IMAGETYPE_JPEG) {
            $src = $this->applyExifOrientation($src, $path);
            $width  = imagesx($src);
            $height = imagesy($src);
        }

        $longEdge = max($width, $height);
        $needsResize = $longEdge > self::MAX_EDGE;

        // Sudah kecil DAN bukan JPEG raksasa → biarkan apa adanya, jangan
        // menyandikan ulang tanpa alasan (setiap penyandian ulang menggerus mutu).
        if (!$needsResize && $file->getSize() <= 400 * 1024) {
            imagedestroy($src);
            return null;
        }

        $scale = $needsResize ? self::MAX_EDGE / $longEdge : 1.0;
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($newW, $newH);

        // PNG & WebP bisa transparan; tanpa ini latarnya jadi hitam pekat.
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($src);

        $ext = match ($type) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
        };
        $tmp = tempnam(sys_get_temp_dir(), 'noudimg') . '.' . $ext;

        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($dst, $tmp, self::QUALITY),
            IMAGETYPE_PNG  => imagepng($dst, $tmp, 8),
            IMAGETYPE_WEBP => imagewebp($dst, $tmp, self::QUALITY),
        };
        imagedestroy($dst);

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            return null;
        }

        // Penyandian ulang tidak selalu menang — PNG datar kecil kerap justru
        // membengkak. Kalau hasilnya lebih besar, buang dan pakai yang asli.
        if (filesize($tmp) >= $file->getSize() && !$needsResize) {
            @unlink($tmp);
            return null;
        }

        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.' . $ext;

        // test: true → berkas dianggap sah apa adanya (bukan hasil unggahan HTTP)
        // dan akan dipindahkan, bukan disalin.
        return new UploadedFile($tmp, $name, null, null, true);
    }

    private function applyExifOrientation(\GdImage $img, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) return $img;

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };

        if ($rotated) {
            imagedestroy($img);
            return $rotated;
        }

        return $img;
    }
}
