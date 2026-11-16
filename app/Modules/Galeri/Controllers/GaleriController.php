<?php

namespace App\Modules\Galeri\Controllers;

use App\Modules\Galeri\Models\GaleriFoto;
use App\Modules\Galeri\Models\GaleriKarya;
use App\Modules\Galeri\Services\FotoProdukService;
use App\Modules\Galeri\Services\GaleriException;
use App\Modules\Galeri\Services\GaleriService;
use App\Modules\Sales\Models\SalesOrder;
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
    public function __construct(private GaleriService $galeri, private FotoProdukService $foto) {}

    public function index(Request $request)
    {
        $this->jaga();

        // Tombol "Foto produk" di Meja Kerja: unggahan berikutnya tertaut pesanan ini, sampai
        // Selesai ditekan (?so=0). Disimpan di sesi supaya bertahan saat berpindah folder.
        if ($request->has('so')) {
            $request->integer('so') > 0
                ? session(['galeri_so' => $request->integer('so')])
                : session()->forget('galeri_so');

            // Info foto pesanan ini sudah diisi → langsung buka folder pesanannya.
            $folder = $request->integer('so') > 0
                ? GaleriKarya::where('sales_order_id', $request->integer('so'))->where('folder', '!=', '')->value('folder') : null;
            if ($folder && !$request->has('p') && $this->galeri->tersambung() && is_dir($this->galeri->abs($folder))) {
                return redirect()->route('cs.galeri', ['p' => $folder]);
            }
        }

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
            'potongan'   => $this->galeri->ukuranPotongan(),
            'maksMb'     => (int) config('galeri.maks_mb'),
            'soAktif'    => $this->soAktif(),
        ]);
    }

    /** Tautkan berkas yang sudah ada di folder ke pesanan yang sedang difoto. */
    public function tautkan(Request $request)
    {
        $this->jaga();
        $request->validate(['p' => 'required|string']);
        $so = $this->soAktif();
        if (!$so) return $this->kembali($request)->with('error', 'Tidak ada pesanan yang sedang difoto.');

        return $this->aksi($request, function () use ($request, $so) {
            $path = $this->galeri->normalisasi((string) $request->input('p'));
            $this->galeri->berkas($path);
            $this->foto->tautkan($path, $so['id'], (int) $request->user()->id);
            return $this->kembali($request)->with('success', 'Tertaut ke ' . $so['nomor'] . '.');
        });
    }

    /** Info foto pesanan yang sedang difoto: lini, layak Galeri, nama instansi, jenis. Membuat foldernya. */
    public function simpanKarya(Request $request)
    {
        $this->jaga();
        $so = $this->soAktif();
        if (!$so) return redirect()->route('cs.galeri')->with('error', 'Tidak ada pesanan yang sedang difoto.');

        $data = $request->validate([
            'lini'          => 'required|string|max:150',
            'layak_galeri'  => 'nullable|boolean',
            'nama_instansi' => 'nullable|required_if:layak_galeri,1|string|max:150',
            'jenis_produk'  => 'nullable|string|max:100',
            'catatan'       => 'nullable|string|max:1000',
        ], ['nama_instansi.required_if' => 'Nama instansi wajib diisi bila foto layak tampil di Galeri web.']);

        try {
            $karya = $this->foto->simpanKarya($so['id'], $data, (int) $request->user()->id);
        } catch (GaleriException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cs.galeri', ['p' => $karya->folder])->with('success', 'Info foto tersimpan. Unggahan masuk ke folder ini.');
    }

    /** Foto utama pesanan (dipakai untuk "Sudah Dipercaya Oleh"). */
    public function fotoUtama(Request $request)
    {
        $this->jaga();
        $request->validate(['p' => 'required|string']);
        $so = $this->soAktif();
        $karya = $so ? GaleriKarya::where('sales_order_id', $so['id'])->first() : null;
        if (!$karya) return $this->kembali($request)->with('error', 'Isi info foto pesanan ini dulu.');

        return $this->aksi($request, function () use ($request, $karya, $so) {
            $path = $this->galeri->normalisasi((string) $request->input('p'));
            $this->galeri->berkas($path);
            abort_unless($this->galeri->jenis($path) === 'gambar', 422, 'Foto utama harus berupa foto.');
            $this->foto->tautkan($path, $so['id'], (int) $request->user()->id);
            $karya->update(['foto_utama' => $path]);
            return $this->kembali($request)->with('success', 'Foto utama ' . $so['nomor'] . ' dipilih.');
        });
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
            $lama = $this->galeri->normalisasi((string) $request->input('p'));
            $baru = $this->galeri->gantiNama($lama, (string) $request->input('nama'));
            $this->foto->pathBerubah($lama, $baru);
            return $this->kembali($request)->with('success', 'Nama diganti.');
        });
    }

    public function pindah(Request $request)
    {
        $this->jaga();
        $request->validate(['p' => 'required|string', 'tujuan' => 'nullable|string']);

        return $this->aksi($request, function () use ($request) {
            $lama = $this->galeri->normalisasi((string) $request->input('p'));
            $baru = $this->galeri->pindah($lama, (string) $request->input('tujuan'));
            $this->foto->pathBerubah($lama, $baru);
            return $this->kembali($request)->with('success', 'Dipindahkan.');
        });
    }

    public function hapus(Request $request)
    {
        $this->jaga();
        $request->validate(['p' => 'required|string']);

        return $this->aksi($request, function () use ($request) {
            $path = $this->galeri->normalisasi((string) $request->input('p'));
            $this->galeri->hapus($path);
            $this->foto->pathDihapus($path);
            return $this->kembali($request)->with('success', 'Dihapus (masih ada di folder #recycle).');
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
            'so'       => 'nullable|integer|exists:sales_orders,id',
            'potongan' => 'required|file',
        ]);

        // Mode pesanan: unggahan selalu ke folder pesanannya (info foto wajib diisi dulu).
        $folder = (string) $request->input('folder');
        if ($request->filled('so')) {
            $karyaFolder = GaleriKarya::where('sales_order_id', $request->integer('so'))->where('folder', '!=', '')->value('folder');
            if (!$karyaFolder) {
                return response()->json(['pesan' => 'Isi info foto pesanan ini dulu (kotak kuning di atas).'], 422);
            }
            $folder = $karyaFolder;
        }

        try {
            $hasil = $this->galeri->terimaPotongan(
                (int) $request->user()->id,
                (string) $request->input('id'),
                (int) $request->input('offset'),
                (int) $request->input('total'),
                (string) $request->input('nama'),
                $folder,
                $request->file('potongan'),
            );
        } catch (GaleriException $e) {
            if ($e->diterima !== null) {
                return response()->json(['pesan' => $e->getMessage(), 'diterima' => $e->diterima], 409);
            }
            return response()->json(['pesan' => $e->getMessage()], 422);
        }

        if (($hasil['selesai'] ?? false) && $request->filled('so')) {
            $this->foto->tautkan($hasil['path'], $request->integer('so'), (int) $request->user()->id);
        }

        return response()->json($hasil);
    }

    // ───────────── Bantuan ─────────────

    /** @return array{id:int, nomor:string, barang:string, jumlah:int}|null */
    private function soAktif(): ?array
    {
        $so = ($id = (int) session('galeri_so')) ? SalesOrder::with('items.product:id,name')->find($id) : null;
        if (!$so) return null;

        $karya = GaleriKarya::where('sales_order_id', $so->id)->where('folder', '!=', '')->first();
        $lini = [];
        if ($this->galeri->tersambung()) {
            try {
                $lini = array_column($this->galeri->subfolder(''), 'nama');
            } catch (GaleriException) {
            }
        }

        return [
            'id'     => $so->id,
            'nomor'  => $so->order_number,
            'barang' => $so->items->map(fn ($i) => $i->description ?: $i->product?->name)->filter()->unique()->implode(', '),
            'jumlah' => GaleriFoto::where('sales_order_id', $so->id)->count(),
            'karya'  => $karya,
            'wajib'  => $this->foto->wajibFoto($so->id),
            'kurang' => $this->foto->kekuranganFoto($so->id),
            'lini'   => $lini,
            'usulan' => $karya?->lini ?? $this->foto->liniUsulan($so->id),
            'jenis'  => config('galeri.jenis_produk', []),
        ];
    }

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

}
