<?php

namespace App\Modules\Galeri\Services;

/**
 * Potong & putar foto (GD) untuk lampiran porto.
 *
 * Supaya yang dilihat admin = hasil akhir, pratinjau di layar dibuat server dengan urutan yang
 * sama: orientasi EXIF dibetulkan → diputar → (diperkecil). Potongan dikirim sebagai PECAHAN
 * (0–1) terhadap pratinjau yang sudah diputar itu, lalu diterapkan ke foto ukuran penuh.
 *
 * GD tidak membaca HEIC; berkas seperti itu tidak bisa diedit (bisaDiedit = false).
 */
class GambarEditor
{
    public function bisaDiedit(string $abs): bool
    {
        $info = @getimagesize($abs);
        return $info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    }

    /** Pratinjau JPEG (sisi terpanjang $px) yang sudah tegak & diputar — untuk layar potong. */
    public function pratinjau(string $sumber, string $tujuan, int $putar, int $px = 1600): void
    {
        $img = $this->muat($sumber, $putar);
        $w = imagesx($img);
        $h = imagesy($img);
        $skala = min(1, $px / max($w, $h));
        if ($skala < 1) {
            $baru = imagescale($img, (int) round($w * $skala), (int) round($h * $skala), IMG_BICUBIC);
            imagedestroy($img);
            $img = $baru;
        }
        $ok = imagejpeg($img, $tujuan, 85);
        imagedestroy($img);
        if (!$ok) throw new GaleriException('Pratinjau gagal dibuat.');
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}|null  $potong  pecahan 0–1 terhadap gambar yang sudah diputar
     * @param  int  $putar  derajat searah jarum jam (kelipatan 90)
     * @param  array{0: int, 1: int}|null  $rasio  [lebar, tinggi]: tanpa $potong → potong tengah sebesar mungkin
     * @return bool false = tak ada yang berubah (tegak, tak diputar, sudah sesuai rasio) — $tujuan tidak ditulis
     */
    public function terapkan(string $sumber, string $tujuan, ?array $potong, int $putar, ?array $rasio = null): bool
    {
        $tipe = (@getimagesize($sumber))[2] ?? null;
        $img = $this->muat($sumber, $putar);

        if (!$potong && $rasio) {
            $potong = $this->potongTengah(imagesx($img), imagesy($img), $rasio);
            if (!$potong && $putar % 360 === 0 && $this->orientasiTerakhir === 1) {
                imagedestroy($img);
                return false;
            }
        }

        if ($potong) {
            $w = imagesx($img);
            $h = imagesy($img);
            $x = (int) round(max(0, min(1, (float) $potong['x'])) * $w);
            $y = (int) round(max(0, min(1, (float) $potong['y'])) * $h);
            $pw = min($w - $x, (int) round(max(0, min(1, (float) $potong['w'])) * $w));
            $ph = min($h - $y, (int) round(max(0, min(1, (float) $potong['h'])) * $h));
            if ($pw > 1 && $ph > 1 && ($pw < $w || $ph < $h)) {
                $baru = imagecrop($img, ['x' => $x, 'y' => $y, 'width' => $pw, 'height' => $ph]);
                if ($baru) {
                    imagedestroy($img);
                    $img = $baru;
                }
            }
        }

        $ok = match ($tipe) {
            IMAGETYPE_PNG  => imagepng($img, $tujuan, 6),
            IMAGETYPE_WEBP => imagewebp($img, $tujuan, 92),
            default        => imagejpeg($img, $tujuan, 92),
        };
        imagedestroy($img);
        if (!$ok) throw new GaleriException('Foto hasil edit gagal disimpan.');
        return true;
    }

    /** Potongan tengah terbesar dengan rasio [w, h] (pecahan 0–1); null = gambar sudah sesuai rasio. */
    public function potongTengah(int $w, int $h, array $rasio): ?array
    {
        [$rw, $rh] = $rasio;
        $target = $rw / $rh;
        if (abs($w / $h - $target) < 0.005) return null;

        if ($w / $h > $target) {           // terlalu lebar → potong kiri-kanan
            $fw = ($h * $target) / $w;
            return ['x' => (1 - $fw) / 2, 'y' => 0, 'w' => $fw, 'h' => 1];
        }
        $fh = ($w / $target) / $h;          // terlalu tinggi → potong atas-bawah
        return ['x' => 0, 'y' => (1 - $fh) / 2, 'w' => 1, 'h' => $fh];
    }

    /** Orientasi EXIF foto terakhir yang dimuat (1 = sudah tegak). */
    private int $orientasiTerakhir = 1;

    /** Baca foto, tegakkan menurut EXIF, lalu putar searah jarum jam. */
    private function muat(string $sumber, int $putar): \GdImage
    {
        if (!$this->bisaDiedit($sumber)) throw new GaleriException('Foto ini tidak bisa diedit (format tidak didukung).');

        // Foto HP 12–50 MP: GD butuh ±4 byte/piksel per salinan.
        @ini_set('memory_limit', '1024M');

        $img = @imagecreatefromstring((string) file_get_contents($sumber));
        if (!$img) throw new GaleriException('Foto gagal dibaca.');

        $this->orientasiTerakhir = 1;
        if (((@getimagesize($sumber))[2] ?? null) === IMAGETYPE_JPEG) $img = $this->orientasiExif($img, $sumber);

        $putar = (($putar % 360) + 360) % 360;
        if ($putar) {
            // imagerotate berputar berlawanan jarum jam.
            $baru = imagerotate($img, 360 - $putar, 0);
            imagedestroy($img);
            $img = $baru;
        }
        return $img;
    }

    private function orientasiExif(\GdImage $img, string $abs): \GdImage
    {
        $o = function_exists('exif_read_data') ? (int) (@exif_read_data($abs)['Orientation'] ?? 1) : 1;
        $this->orientasiTerakhir = $o ?: 1;
        $sudut = match ($o) { 3, 4 => 180, 6, 5 => 270, 8, 7 => 90, default => 0 };
        if ($sudut) {
            $baru = imagerotate($img, $sudut, 0);
            imagedestroy($img);
            $img = $baru;
        }
        if (in_array($o, [2, 4, 5, 7], true)) imageflip($img, IMG_FLIP_HORIZONTAL);
        return $img;
    }
}
