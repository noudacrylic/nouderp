<?php

namespace App\Modules\Galeri\Services;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;

/**
 * Galeri: menelusuri & mengisi shared folder "Foto Produk" di Synology.
 *
 * Sengaja memakai fungsi berkas PHP biasa di atas folder yang di-mount, BUKAN
 * database: folder adalah sumber kebenaran. Berkas yang ditaruh dari Windows
 * Explorer harus langsung tampil di HP tanpa sinkronisasi apa pun, dan struktur
 * folder yang sudah ada (per lini produk) dipertahankan apa adanya.
 *
 * Semua path dari luar berbentuk RELATIF terhadap root ("BC/Custom") dan wajib
 * lewat normalisasi() — itu satu-satunya pagar dari "../" keluar root.
 */
class GaleriService
{
    /** Nama yang tak pernah ditampilkan: metadata Synology/Windows & berkas sementara. */
    private const TERSEMBUNYI = ['thumbs.db', 'desktop.ini', '.ds_store'];

    /** @param array<string, string[]> $ekstensiTambahan jenis berkas selain config galeri.ekstensi */
    public function __construct(private ?string $root = null, private array $ekstensiTambahan = []) {}

    // ───────────── Akar & path ─────────────

    public function root(): string
    {
        $root = rtrim(str_replace('\\', '/', $this->root ?? (string) config('galeri.root')), '/');
        if (! config('galeri.wajib_mount') && ! is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        return $root;
    }

    /**
     * NAS tersambung? Bila `wajib_mount`, root harus berada di perangkat (device)
     * berbeda dari induknya — artinya benar-benar ter-mount. Mount yang putus
     * menyisakan folder kosong di disk server; menulis ke sana berarti foto
     * "hilang" dari NAS tanpa ada yang tahu.
     */
    public function tersambung(): bool
    {
        $root = $this->root();
        if (! is_dir($root)) return false;
        if (! config('galeri.wajib_mount')) return true;

        $a = @stat($root);
        $b = @stat(dirname($root));
        return $a && $b && $a['dev'] !== $b['dev'];
    }

    /**
     * Rapikan path relatif dari luar. Melempar galat bila mencoba keluar root
     * atau masuk folder sistem (#recycle, @eaDir, .xxx).
     */
    public function normalisasi(?string $rel): string
    {
        $rel = str_replace('\\', '/', trim((string) $rel));
        $bagian = [];
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..' || $this->tersembunyi($seg)) {
                throw new GaleriException('Lokasi tidak valid.');
            }
            $bagian[] = $seg;
        }
        return implode('/', $bagian);
    }

    public function abs(string $rel): string
    {
        $rel = $this->normalisasi($rel);
        return $rel === '' ? $this->root() : $this->root() . '/' . $rel;
    }

    private function tersembunyi(string $nama): bool
    {
        $c = $nama[0] ?? '';
        return $c === '.' || $c === '#' || $c === '@'
            || str_starts_with($nama, '~$')
            || in_array(strtolower($nama), self::TERSEMBUNYI, true);
    }

    /** Nama berkas/folder yang aman untuk Windows & Synology. */
    public function namaAman(string $nama): string
    {
        $nama = preg_replace('/[\x00-\x1F<>:"\/\\\\|?*]+/u', ' ', $nama);
        $nama = trim(preg_replace('/\s+/u', ' ', $nama), " .");
        $nama = mb_substr($nama, 0, 150);
        if ($nama === '' || $this->tersembunyi($nama)) {
            throw new GaleriException('Nama tidak valid.');
        }
        return $nama;
    }

    public function jenis(string $nama): string
    {
        $ext = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
        foreach (config('galeri.ekstensi') + $this->ekstensiTambahan as $jenis => $daftar) {
            if (in_array($ext, $daftar, true)) return $jenis;
        }
        return 'lain';
    }

    // ───────────── Membaca ─────────────

    /**
     * Isi satu folder: folder dulu (urut nama, seperti Explorer), lalu berkas
     * terbaru dulu — yang baru diunggah langsung kelihatan di atas.
     *
     * @return array{folder: list<array>, berkas: list<array>}
     */
    public function daftar(string $rel): array
    {
        $rel = $this->normalisasi($rel);
        $dir = $this->abs($rel);
        if (! is_dir($dir)) throw new GaleriException('Folder tidak ditemukan.');

        $folder = $berkas = [];
        foreach (scandir($dir) ?: [] as $nama) {
            if ($nama === '.' || $nama === '..' || $this->tersembunyi($nama)) continue;
            $path = $dir . '/' . $nama;
            $relAnak = $rel === '' ? $nama : $rel . '/' . $nama;

            if (is_dir($path)) {
                $folder[] = ['nama' => $nama, 'path' => $relAnak];
            } elseif (is_file($path)) {
                $berkas[] = [
                    'nama'  => $nama,
                    'path'  => $relAnak,
                    'jenis' => $this->jenis($nama),
                    'ukuran' => (int) @filesize($path),
                    'waktu' => (int) @filemtime($path),
                ];
            }
        }

        usort($folder, fn ($a, $b) => strnatcasecmp($a['nama'], $b['nama']));
        usort($berkas, fn ($a, $b) => [$b['waktu'], $a['nama']] <=> [$a['waktu'], $b['nama']]);

        return ['folder' => $folder, 'berkas' => $berkas];
    }

    /** Subfolder saja — untuk pemilih folder tujuan saat memindah. */
    public function subfolder(string $rel): array
    {
        return $this->daftar($rel)['folder'];
    }

    /** Path absolut berkas yang ada; galat bila tidak ada / bukan berkas. */
    public function berkas(string $rel): string
    {
        $rel = $this->normalisasi($rel);
        $abs = $this->abs($rel);
        if ($rel === '' || ! is_file($abs)) throw new GaleriException('Berkas tidak ditemukan.');
        return $abs;
    }

    // ───────────── Menulis ─────────────

    public function buatFolder(string $induk, string $nama): string
    {
        $this->pastikanTersambung();
        $induk = $this->normalisasi($induk);
        if (! is_dir($this->abs($induk))) throw new GaleriException('Folder induk tidak ditemukan.');

        $nama = $this->namaAman($nama);
        $rel = ltrim($induk . '/' . $nama, '/');
        if (file_exists($this->abs($rel))) throw new GaleriException("Folder \"{$nama}\" sudah ada.");

        if (! @mkdir($this->abs($rel), 0775)) throw new GaleriException('Folder gagal dibuat.');
        return $rel;
    }

    /** Ganti nama. Untuk berkas, ekstensi lama dipertahankan bila tidak ditulis. */
    public function gantiNama(string $rel, string $namaBaru): string
    {
        $this->pastikanTersambung();
        $rel = $this->normalisasi($rel);
        $abs = $this->abs($rel);
        if ($rel === '' || ! file_exists($abs)) throw new GaleriException('Berkas/folder tidak ditemukan.');

        $namaBaru = $this->namaAman($namaBaru);
        if (is_file($abs)) {
            $ext = pathinfo($abs, PATHINFO_EXTENSION);
            if ($ext !== '' && strcasecmp(pathinfo($namaBaru, PATHINFO_EXTENSION), $ext) !== 0) {
                $namaBaru .= '.' . $ext;
            }
        }

        $tujuan = ltrim($this->induk($rel) . '/' . $namaBaru, '/');
        if ($tujuan === $rel) return $rel;
        // Beda huruf besar/kecil saja tetap boleh (Windows/SMB tak peka huruf).
        if (file_exists($this->abs($tujuan)) && strcasecmp($tujuan, $rel) !== 0) {
            throw new GaleriException("Nama \"{$namaBaru}\" sudah dipakai.");
        }
        if (! @rename($abs, $this->abs($tujuan))) throw new GaleriException('Gagal mengganti nama.');
        return $tujuan;
    }

    /** Pindah ke folder lain. Nama bentrok → diberi akhiran " (2)". */
    public function pindah(string $rel, string $folderTujuan): string
    {
        $this->pastikanTersambung();
        $rel = $this->normalisasi($rel);
        $folderTujuan = $this->normalisasi($folderTujuan);
        $abs = $this->abs($rel);
        if ($rel === '' || ! file_exists($abs)) throw new GaleriException('Berkas/folder tidak ditemukan.');
        if (! is_dir($this->abs($folderTujuan))) throw new GaleriException('Folder tujuan tidak ditemukan.');
        if (is_dir($abs) && ($folderTujuan === $rel || str_starts_with($folderTujuan . '/', $rel . '/'))) {
            throw new GaleriException('Folder tidak bisa dipindah ke dalam dirinya sendiri.');
        }
        if ($this->induk($rel) === $folderTujuan) return $rel;

        $tujuan = $this->namaUnik($folderTujuan, basename($rel));
        if (! @rename($abs, $this->abs($tujuan))) throw new GaleriException('Gagal memindahkan.');
        return $tujuan;
    }

    /**
     * "Hapus" = pindah ke folder sampah (#recycle), dengan struktur path aslinya,
     * supaya salah hapus bisa dipulihkan dari DSM atau Explorer.
     */
    public function hapus(string $rel): void
    {
        $this->pastikanTersambung();
        $rel = $this->normalisasi($rel);
        $abs = $this->abs($rel);
        if ($rel === '' || ! file_exists($abs)) throw new GaleriException('Berkas/folder tidak ditemukan.');

        $sampah = trim((string) config('galeri.sampah'), '/');
        $indukSampah = $this->root() . '/' . $sampah . '/' . $this->induk($rel);
        $indukSampah = rtrim($indukSampah, '/');
        if (! is_dir($indukSampah) && ! @mkdir($indukSampah, 0775, true)) {
            throw new GaleriException('Folder sampah tidak bisa ditulis. Periksa Recycle Bin di DSM.');
        }

        $nama = basename($rel);
        $tujuan = $indukSampah . '/' . $nama;
        for ($i = 2; file_exists($tujuan); $i++) {
            $tujuan = $indukSampah . '/' . $this->namaKe($nama, $i);
        }
        if (! @rename($abs, $tujuan)) throw new GaleriException('Gagal menghapus.');
    }

    // ───────────── Unggah bertahap ─────────────

    /**
     * Terima satu potongan unggahan. Potongan dikirim berurutan; `offset` wajib
     * sama dengan panjang yang sudah diterima — bila tidak (sinyal putus, kirim
     * ulang), galat membawa panjang sebenarnya supaya HP melanjutkan dari sana.
     *
     * Potongan ditampung di disk server dan baru dipindah ke NAS setelah utuh:
     * berkas setengah jadi tidak pernah muncul di folder bersama.
     *
     * @return array{selesai: bool, diterima: int, path?: string}
     */
    public function terimaPotongan(int $userId, string $idUnggah, int $offset, int $total, string $nama, string $folder, UploadedFile $potongan): array
    {
        $this->pastikanTersambung();
        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $idUnggah)) throw new GaleriException('ID unggahan tidak valid.');

        $nama = $this->namaAman($nama);
        if ($this->jenis($nama) === 'lain') throw new GaleriException("\"{$nama}\" bukan foto/video.");
        $maks = (int) config('galeri.maks_mb') * 1024 * 1024;
        if ($total <= 0 || $total > $maks) throw new GaleriException('Ukuran berkas melebihi batas ' . config('galeri.maks_mb') . ' MB.');

        $folder = $this->normalisasi($folder);
        if (! is_dir($this->abs($folder))) throw new GaleriException('Folder tujuan tidak ditemukan.');

        $dir = rtrim(config('galeri.tmp_dir'), '/\\') . '/' . $userId;
        if (! is_dir($dir)) @mkdir($dir, 0775, true);
        if ($offset === 0) $this->sapuSisa($dir);
        $part = $dir . '/' . $idUnggah . '.part';

        clearstatcache(true, $part);
        $ada = is_file($part) ? (int) filesize($part) : 0;
        if ($offset === 0 && $ada > 0) {
            @unlink($part);
            $ada = 0;
        }
        if ($ada !== $offset) {
            throw new GaleriException('Potongan tidak berurutan.', $ada);
        }

        $masuk = fopen($potongan->getRealPath(), 'rb');
        $keluar = fopen($part, 'ab');
        stream_copy_to_stream($masuk, $keluar);
        fclose($masuk);
        fclose($keluar);

        clearstatcache(true, $part);
        $diterima = (int) filesize($part);
        if ($diterima > $total) {
            @unlink($part);
            throw new GaleriException('Ukuran unggahan tidak cocok. Ulangi.');
        }
        if ($diterima < $total) {
            return ['selesai' => false, 'diterima' => $diterima];
        }

        $tujuan = $this->namaUnik($folder, $nama);
        // rename() PHP menyalin+menghapus sendiri bila beda perangkat (disk server → NAS).
        if (! @rename($part, $this->abs($tujuan))) {
            @unlink($part);
            throw new GaleriException('Gagal menyimpan ke NAS.');
        }
        @chmod($this->abs($tujuan), 0664);

        return ['selesai' => true, 'diterima' => $diterima, 'path' => $tujuan];
    }

    /**
     * Ukuran potongan (byte): dari config, tapi tak boleh melewati batas unggah PHP — kalau
     * lewat, PHP membuang berkasnya diam-diam.
     */
    public function ukuranPotongan(): int
    {
        $batas = min($this->keByte(ini_get('upload_max_filesize')), $this->keByte(ini_get('post_max_size')));
        $mau = max(1, (int) config('galeri.potongan_mb')) * 1024 * 1024;
        // Sisakan ruang untuk field lain di request multipart.
        return max(256 * 1024, min($mau, $batas - 64 * 1024));
    }

    private function keByte(string|false $nilai): int
    {
        $nilai = trim((string) $nilai);
        if ($nilai === '' || $nilai === '0' || $nilai === '-1') return PHP_INT_MAX;
        $angka = (int) $nilai;
        return match (strtolower(substr($nilai, -1))) {
            'g' => $angka * 1024 ** 3,
            'm' => $angka * 1024 ** 2,
            'k' => $angka * 1024,
            default => $angka,
        };
    }

    /** Potongan yatim (unggahan ditinggal) lebih dari sehari dibuang. */
    private function sapuSisa(string $dir): void
    {
        foreach (glob($dir . '/*.part') ?: [] as $f) {
            if (@filemtime($f) < time() - 86400) @unlink($f);
        }
    }

    // ───────────── Thumbnail ─────────────

    /**
     * Path JPEG thumbnail di disk server (dibuat bila belum ada), atau null bila
     * jenisnya tak bisa dibuatkan (HEIC, video tanpa ffmpeg, berkas rusak).
     * Kunci cache memuat waktu ubah & ukuran → berkas yang ditimpa dari Windows
     * otomatis dapat thumbnail baru.
     */
    public function thumbnail(string $rel): ?string
    {
        $abs = $this->berkas($rel);
        $kunci = sha1($this->normalisasi($rel) . '|' . @filemtime($abs) . '|' . @filesize($abs));
        $dir = rtrim(config('galeri.thumb_dir'), '/\\') . '/' . substr($kunci, 0, 2);
        $out = $dir . '/' . $kunci . '.jpg';
        if (is_file($out)) return $out;

        // Sudah pernah dicoba & gagal: jangan ulangi tiap kali galeri dibuka.
        if (is_file($out . '.gagal')) return null;
        if (! is_dir($dir)) @mkdir($dir, 0775, true);

        $ok = match ($this->jenis($abs)) {
            'gambar' => $this->thumbGambar($abs, $out),
            'video'  => $this->thumbVideo($abs, $out),
            default  => false,
        };
        if (! $ok) {
            @touch($out . '.gagal');
            return null;
        }
        return $out;
    }

    private function thumbGambar(string $abs, string $out): bool
    {
        $info = @getimagesize($abs);
        $type = $info[2] ?? null;
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) return false;

        // Foto kamera 50 MP butuh ±200 MB saat dibuka GD.
        @ini_set('memory_limit', '768M');
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($abs),
            IMAGETYPE_PNG  => @imagecreatefrompng($abs),
            IMAGETYPE_WEBP => @imagecreatefromwebp($abs),
            IMAGETYPE_GIF  => @imagecreatefromgif($abs),
        };
        if (! $src) return false;

        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data($abs)['Orientation'] ?? 1);
            $putar = match ($o) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($putar && ($r = imagerotate($src, $putar, 0))) {
                imagedestroy($src);
                $src = $r;
            }
        }

        $ok = $this->simpanKecil($src, $out);
        imagedestroy($src);
        return $ok;
    }

    private function thumbVideo(string $abs, string $out): bool
    {
        $ffmpeg = (string) config('galeri.ffmpeg');
        if ($ffmpeg === '' || ! is_executable($ffmpeg)) return false;

        $px = (int) config('galeri.thumb_px');
        $p = new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-ss', '1', '-i', $abs,
            '-frames:v', '1', '-vf', "scale='min({$px},iw)':-2", '-q:v', '5', $out]);
        $p->setTimeout(30);
        try {
            $p->run();
        } catch (\Throwable) {
            return false;
        }
        // Video < 1 detik: ulangi dari bingkai pertama.
        if (! is_file($out)) {
            $p = new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $abs,
                '-frames:v', '1', '-vf', "scale='min({$px},iw)':-2", '-q:v', '5', $out]);
            $p->setTimeout(30);
            try { $p->run(); } catch (\Throwable) { return false; }
        }
        return is_file($out) && filesize($out) > 0;
    }

    private function simpanKecil(\GdImage $src, string $out): bool
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $px = (int) config('galeri.thumb_px');
        $skala = min(1, $px / max($w, $h));
        $nw = max(1, (int) round($w * $skala));
        $nh = max(1, (int) round($h * $skala));

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = imagejpeg($dst, $out, 78);
        imagedestroy($dst);
        return $ok;
    }

    // ───────────── Bantuan ─────────────

    /** Folder induk relatif, '' untuk item di akar. Di Windows dirname('/a') memberi "\". */
    private function induk(string $rel): string
    {
        return trim(str_replace('\\', '/', dirname('/' . $rel)), '/');
    }

    private function pastikanTersambung(): void
    {
        if (! $this->tersambung()) {
            throw new GaleriException('NAS tidak tersambung. Hubungi admin — foto belum bisa disimpan.');
        }
    }

    /** Path relatif bebas bentrok di folder: "foto.jpg" → "foto (2).jpg". */
    private function namaUnik(string $folder, string $nama): string
    {
        $rel = ltrim($folder . '/' . $nama, '/');
        for ($i = 2; file_exists($this->abs($rel)); $i++) {
            $rel = ltrim($folder . '/' . $this->namaKe($nama, $i), '/');
        }
        return $rel;
    }

    private function namaKe(string $nama, int $i): string
    {
        $ext = pathinfo($nama, PATHINFO_EXTENSION);
        $dasar = $ext === '' ? $nama : substr($nama, 0, -strlen($ext) - 1);
        return $dasar . " ({$i})" . ($ext === '' ? '' : '.' . $ext);
    }
}
