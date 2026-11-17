<?php

namespace App\Modules\Galeri\Services;

use App\Modules\Galeri\Models\GaleriKarya;
use App\Modules\Galeri\Models\GaleriKotak;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kotak Porto (keputusan 17 Nov 2026): Admin Desain memotret dengan kamera bawaan HP, memilih
 * foto/video di menu Porto PWA → ditampung UTUH di disk server. Di laptop, kartu Meja tahap foto
 * memilih berkas dari kotak (boleh dipotong/diputar) → dipindah ke folder porto pesanan di NAS.
 */
class KotakPortoService
{
    public function __construct(
        private GaleriService $galeri,
        private FotoProdukService $foto,
        private GambarEditor $editor,
    ) {}

    /** Satu potongan unggahan dari HP; selesai → berkas utuh masuk kotak. */
    public function terima(int $userId, string $idUnggah, int $offset, int $total, string $nama, UploadedFile $potongan): array
    {
        $hasil = $this->galeri->rakitPotongan($userId, $idUnggah, $offset, $total, $nama, $potongan);
        if (!$hasil['selesai']) return $hasil;

        $ext = strtolower(pathinfo($hasil['nama'], PATHINFO_EXTENSION));
        $rel = now()->format('Y/m') . '/' . bin2hex(random_bytes(12)) . ($ext ? ".$ext" : '');
        $abs = rtrim(config('galeri.kotak_dir'), '/\\') . '/' . $rel;
        if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0775, true);
        if (!@rename($hasil['part'], $abs)) {
            @unlink($hasil['part']);
            throw new GaleriException('Gagal menyimpan berkas di server.');
        }

        $item = GaleriKotak::create([
            'user_id' => $userId, 'nama' => $hasil['nama'], 'berkas' => $rel,
            'jenis' => $this->galeri->jenis($hasil['nama']), 'ukuran' => (int) filesize($abs),
        ]);

        return ['selesai' => true, 'diterima' => $hasil['diterima'], 'id' => $item->id];
    }

    /** Berkas yang belum dilampirkan, terbaru dulu. */
    public function belumDipakai(): Collection
    {
        return GaleriKotak::with('user:id,name')->whereNull('dipakai_at')->latest('id')->get()
            ->filter(fn (GaleriKotak $k) => is_file($k->abs()))->values();
    }

    public function hapus(GaleriKotak $k): void
    {
        abort_if($k->dipakai_at, 422, 'Berkas ini sudah dilampirkan ke pesanan.');
        @unlink($k->abs());
        $k->delete();
    }

    public function bisaDiedit(GaleriKotak $k): bool
    {
        return $k->jenis === 'gambar' && $this->editor->bisaDiedit($k->abs());
    }

    /** Pratinjau untuk layar potong (tegak + diputar, ≤1600px); di-cache di thumb_dir. */
    public function pratinjau(GaleriKotak $k, int $putar): string
    {
        $putar = ((intdiv($putar, 90) % 4) + 4) % 4 * 90;
        $abs = $k->abs();
        $out = rtrim(config('galeri.thumb_dir'), '/\\') . '/pratinjau/' . sha1($abs . '|' . @filemtime($abs) . '|' . $putar) . '.jpg';
        if (!is_file($out)) {
            if (!is_dir(dirname($out))) @mkdir(dirname($out), 0775, true);
            $this->editor->pratinjau($abs, $out, $putar);
        }
        return $out;
    }

    /**
     * Lampirkan berkas kotak ke pesanan: dipindah ke folder porto pesanan di NAS & ditautkan.
     * Foto yang diedit: hasil edit di folder porto, aslinya di subfolder "asli".
     *
     * @param  array<int, array{id: int, putar?: int, potong?: array{x: float, y: float, w: float, h: float}|null}>  $pilihan
     * @return int jumlah berkas yang dilampirkan
     */
    public function lampirkan(int $soId, array $pilihan, int $userId): int
    {
        if (!$pilihan) throw new GaleriException('Pilih minimal satu gambar.');

        $items = GaleriKotak::whereIn('id', array_column($pilihan, 'id'))->whereNull('dipakai_at')->get()->keyBy('id');
        if ($items->count() !== count(array_unique(array_column($pilihan, 'id')))) {
            throw new GaleriException('Ada berkas yang sudah dipakai atau dihapus. Muat ulang daftarnya.');
        }
        if (!$items->contains(fn ($k) => $k->jenis === 'gambar')) throw new GaleriException('Lampirkan minimal satu foto (bukan hanya video).');

        $folder = $this->foto->folderPorto($soId, $userId);
        $dasar = str_replace('/', '-', (string) DB::table('sales_orders')->where('id', $soId)->value('order_number'));
        $utama = null;

        foreach ($pilihan as $p) {
            $k = $items[$p['id']];
            $ext = strtolower(pathinfo($k->nama, PATHINFO_EXTENSION)) ?: ($k->jenis === 'video' ? 'mp4' : 'jpg');
            $nama = "$dasar.$ext";
            $putar = (int) ($p['putar'] ?? 0);
            $potong = $p['potong'] ?? null;
            $tmp = $k->abs() . '.edit.' . $ext;
            // Semua foto dibuat serasio (config galeri.rasio_porto): tanpa potongan dari admin →
            // dipotong tengah. Foto yang sudah tegak & serasio tak disentuh.
            $edit = $k->jenis === 'gambar' && $this->editor->bisaDiedit($k->abs())
                && $this->editor->terapkan($k->abs(), $tmp, $potong, $putar, config('galeri.rasio_porto'));

            if ($edit) {
                try {
                    $path = $this->galeri->pindahMasuk($tmp, $folder, $nama);
                } finally {
                    @unlink($tmp);
                }
                $this->galeri->pindahMasuk($k->abs(), $this->galeri->pastikanFolder($folder . '/asli'), basename($path));
            } else {
                $path = $this->galeri->pindahMasuk($k->abs(), $folder, $nama);
            }

            $this->foto->tautkan($path, $soId, $userId);
            $k->update(['sales_order_id' => $soId, 'path_nas' => $path, 'dipakai_at' => now()]);
            if (!$utama && $k->jenis === 'gambar') $utama = $path;
        }

        $karya = GaleriKarya::where('sales_order_id', $soId)->first();
        if ($karya && !$karya->foto_utama && $utama) $karya->update(['foto_utama' => $utama]);

        return $items->count();
    }

    /** Buang berkas kotak yang tak pernah dilampirkan setelah sekian hari. */
    public function sapu(): int
    {
        $n = 0;
        GaleriKotak::whereNull('dipakai_at')->where('created_at', '<', now()->subDays((int) config('galeri.kotak_hari', 14)))
            ->get()->each(function (GaleriKotak $k) use (&$n) {
                @unlink($k->abs());
                $k->delete();
                $n++;
            });
        return $n;
    }
}
