<?php

namespace App\Modules\Seo\Controllers;

use App\Modules\Seo\Models\SeoGoogleAkun;
use App\Modules\Seo\Models\SeoJenis;
use App\Modules\Seo\Models\SeoKataKunci;
use App\Modules\Seo\Models\SeoSaranHalaman;
use App\Modules\Seo\Services\KelompokKataKunciService;
use App\Modules\Seo\Services\SaranHalamanService;
use Illuminate\Support\Facades\Cache;
use App\Modules\Seo\Services\SearchConsoleService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Store → Kata Kunci: bank kata kunci SEO per jenis produk (riset AI + Search Console + manual).
 * Bahan caption/alt foto Galeri web nanti.
 */
class KataKunciController extends Controller
{
    public function __construct(private SearchConsoleService $gsc) {}

    public function index(Request $request)
    {
        $jenis = SeoJenis::withCount('kataKunci')->orderBy('urutan')->orderBy('nama')->get();
        $pilih = $request->query('jenis');
        $q = trim((string) $request->query('q'));

        $kata = SeoKataKunci::with('jenis:id,nama')
            ->when($pilih === 'tanpa', fn ($w) => $w->whereNull('seo_jenis_id'))
            ->when($pilih && $pilih !== 'tanpa', fn ($w) => $w->where('seo_jenis_id', (int) $pilih))
            ->when($q !== '', fn ($w) => $w->where('kata', 'like', '%' . $q . '%'))
            ->when($request->query('sumber'), fn ($w, $s) => $w->where('sumber', $s))
            ->orderByRaw('gsc_tayang IS NULL')->orderByDesc('gsc_tayang')
            ->orderByRaw("FIELD(perkiraan, 'tinggi', 'sedang', 'rendah') = 0")->orderByRaw("FIELD(perkiraan, 'tinggi', 'sedang', 'rendah')")
            ->orderBy('kata')
            ->paginate(per_page_size())->withQueryString();

        return view('erp.store.kata-kunci.index', [
            'jenis'      => $jenis,
            'jenisAktif' => $pilih && $pilih !== 'tanpa' ? $jenis->firstWhere('id', (int) $pilih) : null,
            'pilih'      => $pilih,
            'q'          => $q,
            'kata'       => $kata,
            'tanpaJenis' => SeoKataKunci::whereNull('seo_jenis_id')->count(),
            'gsc'        => ['tersambung' => $this->gsc->tersambung(), 'email' => $this->gsc->emailAkun(), 'status' => $this->gsc->status(),
                             'klien' => $this->gsc->klienSiap(), 'callback' => route('store.kata-kunci.google.callback'),
                             'client_id' => SeoGoogleAkun::query()->value('client_id')],
        ]);
    }

    public function simpan(Request $request)
    {
        $d = $request->validate([
            'kata'         => 'required|string|max:150',
            'seo_jenis_id' => 'nullable|exists:seo_jenis,id',
            'niat'         => ['nullable', Rule::in(array_keys(SeoKataKunci::NIAT))],
        ]);
        $kata = SeoKataKunci::baku($d['kata']);
        if (SeoKataKunci::where('kata', $kata)->exists()) return back()->with('error', "\"$kata\" sudah ada di bank.");

        SeoKataKunci::create(['kata' => $kata, 'seo_jenis_id' => $d['seo_jenis_id'] ?? null, 'jenis_oleh' => 'manual', 'niat' => $d['niat'] ?? null, 'sumber' => 'manual']);
        return back()->with('success', "\"$kata\" ditambahkan.");
    }

    public function ubah(Request $request, SeoKataKunci $kata)
    {
        $d = $request->validate([
            'seo_jenis_id' => 'nullable|exists:seo_jenis,id',
            'niat'         => ['nullable', Rule::in(array_keys(SeoKataKunci::NIAT))],
            'aktif'        => 'nullable|boolean',
        ]);
        $ubah = array_intersect_key($d, $request->all());
        // Jenis dipilih orang → tak pernah ditimpa pengelompokan otomatis.
        if (array_key_exists('seo_jenis_id', $ubah)) $ubah['jenis_oleh'] = 'manual';
        $kata->update($ubah);
        return back()->with('success', "\"{$kata->kata}\" diperbarui.");
    }

    public function hapus(SeoKataKunci $kata)
    {
        $kata->delete();
        return back()->with('success', "\"{$kata->kata}\" dihapus.");
    }

    /** Tandai jenis untuk diriset; dikerjakan penjadwal (seo:riset-kata-kunci --antrean). */
    public function riset(Request $request)
    {
        $id = $request->input('jenis');
        $q = SeoJenis::where('aktif', true)->whereNull('riset_diminta_at');
        $n = ($id === 'semua' ? $q : $q->whereKey((int) $id))->update(['riset_diminta_at' => now()]);

        return back()->with('success', $n ? "$n jenis masuk antrean riset (±1–3 menit per jenis). Muat ulang halaman untuk melihat hasilnya." : 'Sudah dalam antrean.');
    }

    public function simpanJenis(Request $request)
    {
        $d = $request->validate(['nama' => 'required|string|max:100|unique:seo_jenis,nama', 'petunjuk' => 'nullable|string|max:500']);
        $j = SeoJenis::create($d + ['aktif' => true, 'urutan' => (int) SeoJenis::max('urutan') + 1]);
        return redirect()->route('store.kata-kunci.index', ['jenis' => $j->id])->with('success', "Jenis \"{$j->nama}\" ditambahkan.");
    }

    public function ubahJenis(Request $request, SeoJenis $jenis)
    {
        $d = $request->validate(['petunjuk' => 'nullable|string|max:500', 'aktif' => 'nullable|boolean']);
        $jenis->update(array_intersect_key($d, $request->all()));
        return back()->with('success', "Jenis \"{$jenis->nama}\" diperbarui.");
    }

    /** Kelompokkan kata tanpa jenis / hasil alias dengan AI (Haiku; ±150 kata per panggilan, beberapa detik). */
    public function kelompokkan(KelompokKataKunciService $kelompok)
    {
        try {
            $k = $kelompok->kelompokkan(600);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', "{$k['diperiksa']} kata diperiksa AI, {$k['berubah']} pindah jenis.");
    }

    // ───────────── Laporan "tampil tapi tak diklik" ─────────────

    public function halaman()
    {
        return view('erp.store.kata-kunci.halaman', [
            'saran'      => SeoSaranHalaman::orderByRaw("FIELD(status, 'baru', 'dipakai', 'diabaikan')")->orderByDesc('tayang')->get(),
            'terakhir'   => Cache::get('seo.saran.terakhir'),
            'antre'      => Cache::has(SaranHalamanService::KUNCI_ANTREAN),
            'tersambung' => $this->gsc->tersambung(),
        ]);
    }

    public function susunHalaman()
    {
        if (!$this->gsc->tersambung()) return back()->with('error', 'Search Console belum disambungkan.');
        Cache::put(SaranHalamanService::KUNCI_ANTREAN, true, 3600);
        return back()->with('success', 'Laporan sedang disusun (±1–2 menit). Muat ulang halaman ini sebentar lagi.');
    }

    public function pakaiSaran(Request $request, SeoSaranHalaman $saran, SaranHalamanService $svc)
    {
        $d = $request->validate(['judul' => 'required|string|max:255', 'deskripsi' => 'required|string|max:500']);
        try {
            $svc->pakai($saran, trim($d['judul']), trim($d['deskripsi']));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Judul & deskripsi produk diperbarui — tayang di web ±5 menit, terlihat di Google setelah halamannya dirayapi ulang (beberapa hari).');
    }

    public function abaikanSaran(SeoSaranHalaman $saran)
    {
        $saran->update(['status' => $saran->status === 'diabaikan' ? 'baru' : 'diabaikan']);
        return back();
    }

    // ───────────── Sambungan Google (OAuth) ─────────────

    /** OAuth client ID + secret dari Google Cloud (diisi sekali di halaman ini). */
    public function simpanKlien(Request $request)
    {
        $d = $request->validate(['client_id' => 'required|string|max:255', 'client_secret' => 'required|string|max:255']);
        $a = SeoGoogleAkun::satu();
        $a->fill(['client_id' => trim($d['client_id']), 'client_secret' => trim($d['client_secret'])])->save();
        return back()->with('success', 'OAuth client tersimpan. Sekarang tekan "Sambungkan Google".');
    }

    public function sambung(Request $request)
    {
        if (!$this->gsc->klienSiap()) return back()->with('error', 'Isi OAuth client ID & secret dulu.');
        $state = bin2hex(random_bytes(16));
        $request->session()->put('seo_google_state', $state);
        return redirect()->away($this->gsc->urlSambung($state, route('store.kata-kunci.google.callback')));
    }

    public function callback(Request $request)
    {
        $state = $request->session()->pull('seo_google_state');
        $balik = redirect()->route('store.kata-kunci.index');
        if (!$state || !hash_equals($state, (string) $request->query('state'))) return $balik->with('error', 'Sambungan dibatalkan (sesi tidak cocok). Coba lagi.');
        if ($request->query('error')) return $balik->with('error', 'Google: ' . $request->query('error'));

        try {
            $email = $this->gsc->tukarKode((string) $request->query('code'), route('store.kata-kunci.google.callback'), (int) $request->user()->id);
            $situs = $this->gsc->daftarSitus();
        } catch (\Throwable $e) {
            return $balik->with('error', $e->getMessage());
        }

        $target = (string) config('services.gsc.situs');
        if (!in_array($target, $situs, true)) {
            return $balik->with('error', "Tersambung sebagai $email, tapi akun ini tidak punya akses ke $target di Search Console. Tambahkan $email sebagai pengguna properti itu, atau sambungkan dengan akun pemiliknya.");
        }
        return $balik->with('success', "Search Console tersambung sebagai $email. Tekan \"Tarik sekarang\" untuk mengambil data pertama.");
    }

    public function putus()
    {
        $this->gsc->putus();
        return back()->with('success', 'Sambungan Google diputus.');
    }

    public function tarikGsc()
    {
        if (!$this->gsc->tersambung()) return back()->with('error', 'Search Console belum disambungkan.');
        try {
            $h = $this->gsc->tarik();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        $pesan = "{$h['baris']} kata pencarian ditarik dari Search Console, {$h['baru']} kata baru.";
        if ($h['baru']) {
            try {
                $k = app(KelompokKataKunciService::class)->kelompokkan(600);
                $pesan .= " {$k['diperiksa']} dikelompokkan AI.";
            } catch (\Throwable) {
                $pesan .= ' (Pengelompokan AI gagal — coba tombol Kelompokkan.)';
            }
        }
        return back()->with('success', $pesan);
    }
}
