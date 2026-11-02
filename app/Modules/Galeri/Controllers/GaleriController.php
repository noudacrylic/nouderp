<?php

namespace App\Modules\Galeri\Controllers;

use App\Modules\Galeri\Services\GaleriException;
use App\Modules\Galeri\Services\GaleriService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Menu Galeri di PWA `/cs`: foto & video produk langsung ke shared folder
 * "Foto Produk" di Synology (lihat config/galeri.php).
 *
 * Penjaganya izin `crm.galeri` (centang di User & Akses), bukan akses chat:
 * yang memotret produk belum tentu membalas chat, dan sebaliknya.
 * Siapa pun yang diberi izin boleh semua — buat folder, pindah, hapus.
 */
class GaleriController extends Controller
{
    public function __construct(private GaleriService $galeri) {}

    public function index(Request $request)
    {
        $this->jaga();

        try {
            $path = $this->galeri->normalisasi($request->query('p'));
        } catch (GaleriException) {
            return redirect()->route('cs.galeri');
        }

        $tersambung = $this->galeri->tersambung();
        $isi = ['folder' => [], 'berkas' => []];
        if ($tersambung) {
            try {
                $isi = $this->galeri->daftar($path);
            } catch (GaleriException $e) {
                if ($path !== '') {
                    return redirect()->route('cs.galeri')->with('error', $e->getMessage());
                }
            }
        }

        return view('cs.galeri', [
            'path'       => $path,
            'remah'      => $this->remah($path),
            'folder'     => $isi['folder'],
            'berkas'     => $isi['berkas'],
            'tersambung' => $tersambung,
            'potongan'   => $this->ukuranPotongan(),
            'maksMb'     => (int) config('galeri.maks_mb'),
        ]);
    }

    /** JSON subfolder — pemilih folder tujuan saat memindah. */
    public function folder(Request $request)
    {
        $this->jaga();
        try {
            $path = $this->galeri->normalisasi($request->query('p'));
            return response()->json([
                'path'   => $path,
                'remah'  => $this->remah($path),
                'folder' => $this->galeri->subfolder($path),
            ]);
        } catch (GaleriException $e) {
            return response()->json(['pesan' => $e->getMessage()], 422);
        }
    }

    public function thumb(Request $request)
    {
        $this->jaga();
        try {
            $thumb = $this->galeri->thumbnail((string) $request->query('p'));
        } catch (GaleriException) {
            abort(404);
        }
        abort_unless($thumb, 404);

        // URL memuat ?v=waktu-ubah, jadi aman disimpan lama di HP.
        return response()->file($thumb, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'private, max-age=2592000',
        ]);
    }

    /** Berkas asli. BinaryFileResponse melayani Range → video bisa digeser. */
    public function berkas(Request $request)
    {
        $this->jaga();
        try {
            $abs = $this->galeri->berkas((string) $request->query('p'));
        } catch (GaleriException) {
            abort(404);
        }

        if ($request->boolean('unduh')) {
            return response()->download($abs, basename($abs));
        }
        return response()->file($abs, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function buatFolder(Request $request)
    {
        $this->jaga();
        $request->validate(['nama' => 'required|string|max:150']);

        return $this->aksi($request, function () use ($request) {
            $baru = $this->galeri->buatFolder((string) $request->input('p'), (string) $request->input('nama'));
            // Folder baru langsung dibuka: hampir selalu dibuat untuk diisi.
            return redirect()->route('cs.galeri', ['p' => $baru])->with('success', 'Folder dibuat.');
        });
    }

    public function gantiNama(Request $request)
    {
        $this->jaga();
        $request->validate(['p' => 'required|string', 'nama' => 'required|string|max:150']);

        return $this->aksi($request, function () use ($request) {
            $this->galeri->gantiNama((string) $request->input('p'), (string) $request->input('nama'));
            return $this->kembali($request)->with('success', 'Nama diganti.');
        });
    }

    public function pindah(Request $request)
    {
        $this->jaga();
        $request->validate(['p' => 'required|string', 'tujuan' => 'nullable|string']);

        return $this->aksi($request, function () use ($request) {
            $this->galeri->pindah((string) $request->input('p'), (string) $request->input('tujuan'));
            return $this->kembali($request)->with('success', 'Dipindahkan.');
        });
    }

    public function hapus(Request $request)
    {
        $this->jaga();
        $request->validate(['p' => 'required|string']);

        return $this->aksi($request, function () use ($request) {
            $this->galeri->hapus((string) $request->input('p'));
            return $this->kembali($request)->with('success', 'Dihapus (masuk Recycle Bin Synology).');
        });
    }

    /** Satu potongan unggahan (lihat public/js/galeri.js). */
    public function unggah(Request $request)
    {
        $this->jaga();
        $request->validate([
            'id'       => 'required|string',
            'offset'   => 'required|integer|min:0',
            'total'    => 'required|integer|min:1',
            'nama'     => 'required|string|max:255',
            'folder'   => 'nullable|string',
            'potongan' => 'required|file',
        ]);

        try {
            $hasil = $this->galeri->terimaPotongan(
                (int) $request->user()->id,
                (string) $request->input('id'),
                (int) $request->input('offset'),
                (int) $request->input('total'),
                (string) $request->input('nama'),
                (string) $request->input('folder'),
                $request->file('potongan'),
            );
        } catch (GaleriException $e) {
            if ($e->diterima !== null) {
                return response()->json(['pesan' => $e->getMessage(), 'diterima' => $e->diterima], 409);
            }
            return response()->json(['pesan' => $e->getMessage()], 422);
        }

        return response()->json($hasil);
    }

    // ───────────── Bantuan ─────────────

    private function jaga(): void
    {
        abort_unless(user_can_access('crm.galeri'), 403, 'Akun ini belum diberi akses Galeri.');
    }

    private function aksi(Request $request, \Closure $kerja)
    {
        try {
            return $kerja();
        } catch (GaleriException $e) {
            return $this->kembali($request)->with('error', $e->getMessage());
        }
    }

    /** Kembali ke folder yang sedang dibuka (input `kembali`). */
    private function kembali(Request $request)
    {
        try {
            $p = $this->galeri->normalisasi($request->input('kembali'));
        } catch (GaleriException) {
            $p = '';
        }
        return redirect()->route('cs.galeri', $p === '' ? [] : ['p' => $p]);
    }

    /** @return list<array{nama: string, path: string}> */
    private function remah(string $path): array
    {
        $remah = [['nama' => config('galeri.nama'), 'path' => '']];
        $jalan = '';
        foreach ($path === '' ? [] : explode('/', $path) as $seg) {
            $jalan = ltrim($jalan . '/' . $seg, '/');
            $remah[] = ['nama' => $seg, 'path' => $jalan];
        }
        return $remah;
    }

    /**
     * Ukuran potongan (byte): dari config, tapi tak boleh melewati batas
     * unggah PHP — kalau lewat, PHP membuang berkasnya diam-diam.
     */
    private function ukuranPotongan(): int
    {
        $batas = min(
            $this->keByte(ini_get('upload_max_filesize')),
            $this->keByte(ini_get('post_max_size')),
        );
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
}
