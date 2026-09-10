<?php
/**
 * Ikon PWA CRM (/cs): gelembung chat di atas gradasi teal.
 *
 * Dibuat terpisah dari ikon Karyawan (centang hijau) karena keduanya terpasang
 * berdampingan di layar HP yang sama — dua ikon mirip berarti CS membuka
 * aplikasi perizinan setiap kali buru-buru membalas pelanggan.
 */

const SKALA = 4; // digambar besar lalu dikecilkan: satu-satunya cara dapat tepi halus di GD.

function warna($img, string $hex, float $alpha = 0.0)
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

    return imagecolorallocatealpha($img, $r, $g, $b, (int) round($alpha * 127));
}

/** Persegi bersudut bulat, digambar dari empat busur + dua persegi. */
function persegiBulat($img, float $x, float $y, float $w, float $h, float $r, $warna): void
{
    $r = min($r, $w / 2, $h / 2);

    imagefilledrectangle($img, (int) ($x + $r), (int) $y, (int) ($x + $w - $r), (int) ($y + $h), $warna);
    imagefilledrectangle($img, (int) $x, (int) ($y + $r), (int) ($x + $w), (int) ($y + $h - $r), $warna);

    $d = (int) ($r * 2);
    imagefilledarc($img, (int) ($x + $r),      (int) ($y + $r),      $d, $d, 180, 270, $warna, IMG_ARC_PIE);
    imagefilledarc($img, (int) ($x + $w - $r), (int) ($y + $r),      $d, $d, 270, 360, $warna, IMG_ARC_PIE);
    imagefilledarc($img, (int) ($x + $r),      (int) ($y + $h - $r), $d, $d, 90,  180, $warna, IMG_ARC_PIE);
    imagefilledarc($img, (int) ($x + $w - $r), (int) ($y + $h - $r), $d, $d, 0,   90,  $warna, IMG_ARC_PIE);
}

function buat(int $ukuran): \GdImage
{
    $s   = $ukuran * SKALA;
    $img = imagecreatetruecolor($s, $s);

    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefilledrectangle($img, 0, 0, $s, $s, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagealphablending($img, true);

    /*
     * Gradasi teal, bukan hijau: warnanya sama dengan bilah kepala aplikasi
     * (#0f766e) supaya ikon dan layar terasa satu benda, sekaligus jelas beda
     * dari hijau ikon Karyawan.
     */
    for ($y = 0; $y < $s; $y++) {
        $t = $y / max(1, $s - 1);
        $r = (int) round(0x14 + (0x0f - 0x14) * $t);
        $g = (int) round(0xb8 + (0x76 - 0xb8) * $t);
        $b = (int) round(0xa6 + (0x6e - 0xa6) * $t);
        imagefilledrectangle($img, 0, $y, $s, $y, imagecolorallocate($img, $r, $g, $b));
    }

    /*
     * Sudut dibulatkan dengan radius yang sama seperti ikon lama (≈12,5%),
     * lalu di luar itu dibuat transparan. Peluncur Android yang memakai
     * varian maskable akan memotongnya sendiri; yang 'any' tampil apa adanya.
     */
    $radius = $s * 0.125;
    $sudut  = [[$radius, $radius], [$s - $radius, $radius], [$radius, $s - $radius], [$s - $radius, $s - $radius]];
    $bening = imagecolorallocatealpha($img, 0, 0, 0, 127);

    imagealphablending($img, false);

    for ($y = 0; $y < $s; $y++) {
        for ($x = 0; $x < $s; $x++) {
            $luar = ($x < $radius && $y < $radius && hypot($x - $sudut[0][0], $y - $sudut[0][1]) > $radius)
                 || ($x > $s - $radius && $y < $radius && hypot($x - $sudut[1][0], $y - $sudut[1][1]) > $radius)
                 || ($x < $radius && $y > $s - $radius && hypot($x - $sudut[2][0], $y - $sudut[2][1]) > $radius)
                 || ($x > $s - $radius && $y > $s - $radius && hypot($x - $sudut[3][0], $y - $sudut[3][1]) > $radius);

            if ($luar) {
                imagesetpixel($img, $x, $y, $bening);
            }
        }
    }

    imagealphablending($img, true);

    // ---------------------------------------------------------- gelembung chat
    $putih = warna($img, '#ffffff');

    $bw = $s * 0.62;
    $bh = $s * 0.46;
    $bx = ($s - $bw) / 2;
    $by = $s * 0.22;

    persegiBulat($img, $bx, $by, $bw, $bh, $s * 0.13, $putih);

    /*
     * Ekor gelembung. Tanpa ekor, bentuknya cuma persegi bulat putih —
     * dari jarak ikon 48px itu tak terbaca sebagai chat sama sekali.
     */
    imagefilledpolygon($img, [
        (int) ($bx + $bw * 0.20), (int) ($by + $bh - 2),
        (int) ($bx + $bw * 0.46), (int) ($by + $bh - 2),
        (int) ($bx + $bw * 0.20), (int) ($by + $bh + $s * 0.13),
    ], $putih);

    // Tiga titik: penanda percakapan yang masih terbaca di ukuran terkecil.
    $titik = warna($img, '#0f766e');
    $jari  = $s * 0.045;

    foreach ([0.30, 0.50, 0.70] as $posisi) {
        imagefilledellipse(
            $img,
            (int) ($bx + $bw * $posisi),
            (int) ($by + $bh * 0.46),
            (int) ($jari * 2),
            (int) ($jari * 2),
            $titik
        );
    }

    $kecil = imagescale($img, $ukuran, $ukuran, IMG_BICUBIC_FIXED);
    imagedestroy($img);
    imagesavealpha($kecil, true);

    return $kecil;
}

// Dijalankan sesekali saja (php tools/ikon_cs.php); hasilnya PNG yang ikut
// masuk repo, jadi tak ada langkah build yang harus diingat saat deploy.
$tujuan = $argv[1] ?? __DIR__ . '/../public/icons';

foreach ([180, 192, 512] as $ukuran) {
    $img  = buat($ukuran);
    $file = rtrim($tujuan, '/\\') . "/cs-icon-{$ukuran}.png";

    imagepng($img, $file, 9);
    imagedestroy($img);

    echo "tulis {$file}\n";
}
