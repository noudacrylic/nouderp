<?php

namespace App\Modules\Galeri\Controllers;

use App\Modules\Galeri\Services\FotoProdukService;
use App\Modules\Galeri\Services\GaleriException;
use App\Modules\Galeri\Services\GaleriService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * Tab Foto di panel Produk chat CRM: menelusuri folder Foto Produk (NAS) sebagai referensi, HANYA
 * LIHAT — tak ada buat/pindah/hapus (itu di menu Galeri, izin crm.galeri). Dijaga akses Inbox,
 * dan hanya melayani gambar & video, bukan docx/xlsx yang ikut tersimpan di folder lini.
 * Foto produk custom yang tertaut pesanan tampil dengan nomor pesanan & nama barangnya.
 */
class FotoReferensiController extends Controller
{
    private const KUNCI_INDEKS = 'galeri.indeks-referensi';

    public function __construct(private GaleriService $galeri, private FotoProdukService $foto) {}

    public function cari(Request $request)
    {
        $kata = trim((string) $request->query('q'));
        try {
            $folder = $this->galeri->normalisasi($request->query('folder'));
        } catch (GaleriException) {
            $folder = '';
        }

        // Foto custom tertaut pesanan: pencarian lewat nomor pesanan / SKU / nama barang.
        $custom = collect($folder === '' ? $this->foto->cari($kata, $kata === '' ? 12 : 30) : [])
            ->map(fn ($f) => $this->kartu($f['path'], $f['jenis'], $f['pesanan'], $f['barang']));

        if ($kata !== '') {
            $indeks = $this->indeks();
            $cocok = fn (string $p) => mb_stripos($p, $kata) !== false;
            $tertaut = $custom->pluck('path')->flip();

            return response()->json([
                'folder'    => '',
                'subfolder' => collect($indeks['folder'])->filter($cocok)->take(20)
                    ->map(fn ($p) => ['nama' => basename($p), 'path' => $p, 'induk' => $this->induk($p)])->values(),
                'foto'      => $custom->concat(collect($indeks['berkas'])
                    ->filter(fn ($p) => $cocok($p) && !isset($tertaut[$p]))->take(60)
                    ->map(fn ($p) => $this->kartu($p, $this->galeri->jenis($p))))->values(),
            ]);
        }

        try {
            $isi = $this->galeri->daftar($folder);
        } catch (GaleriException) {
            return response()->json(['folder' => $folder, 'subfolder' => [], 'foto' => [], 'galat' => 'Folder tidak ditemukan.']);
        }

        return response()->json([
            'folder'    => $folder,
            'subfolder' => $isi['folder'],
            'custom'    => $custom->values(),
            'foto'      => collect($isi['berkas'])->whereIn('jenis', ['gambar', 'video'])->take(300)
                ->map(fn ($b) => $this->kartu($b['path'], $b['jenis']))->values(),
        ]);
    }

    public function thumb(Request $request)
    {
        try {
            $thumb = $this->galeri->thumbnail($this->path($request));
        } catch (GaleriException) {
            abort(404);
        }
        abort_unless($thumb, 404);

        return response()->file($thumb, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=2592000']);
    }

    public function berkas(Request $request)
    {
        try {
            $abs = $this->galeri->berkas($this->path($request));
        } catch (GaleriException) {
            abort(404);
        }

        return response()->file($abs, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** Path dari query, hanya gambar/video. */
    private function path(Request $request): string
    {
        $p = $this->galeri->normalisasi($request->query('p'));
        if (!in_array($this->galeri->jenis($p), ['gambar', 'video'], true)) {
            throw new GaleriException('Bukan foto/video.');
        }
        return $p;
    }

    private function kartu(string $path, string $jenis, string $pesanan = '', string $barang = ''): array
    {
        return [
            'path'    => $path,
            'nama'    => basename($path),
            'jenis'   => $jenis,
            'induk'   => $this->induk($path),
            'pesanan' => $pesanan,
            'barang'  => $barang,
            'thumb'   => route('crm.inbox.foto.thumb', ['p' => $path]),
            'url'     => route('crm.inbox.foto.berkas', ['p' => $path]),
        ];
    }

    private function induk(string $path): string
    {
        $i = strrpos($path, '/');
        return $i === false ? '' : substr($path, 0, $i);
    }

    /**
     * Daftar seluruh folder & gambar/video di NAS untuk pencarian nama. Menyisir folder lewat SMB
     * tiap ketikan terlalu lambat, jadi disimpan 10 menit: unggahan baru paling lambat 10 menit
     * kemudian ikut tercari (menelusuri folder selalu langsung terbaru).
     *
     * @return array{folder: list<string>, berkas: list<string>}
     */
    private function indeks(): array
    {
        return Cache::remember(self::KUNCI_INDEKS, 600, function () {
            $folder = $berkas = [];
            $sisir = function (string $rel) use (&$sisir, &$folder, &$berkas) {
                try {
                    $isi = $this->galeri->daftar($rel);
                } catch (GaleriException) {
                    return;
                }
                foreach ($isi['berkas'] as $b) {
                    if (in_array($b['jenis'], ['gambar', 'video'], true)) $berkas[] = $b['path'];
                }
                foreach ($isi['folder'] as $f) {
                    $folder[] = $f['path'];
                    $sisir($f['path']);
                }
            };
            $sisir('');

            return ['folder' => $folder, 'berkas' => $berkas];
        });
    }
}
