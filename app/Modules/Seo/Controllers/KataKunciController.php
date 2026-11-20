<?php

namespace App\Modules\Seo\Controllers;

use App\Modules\Seo\Models\SeoJenis;
use App\Modules\Seo\Models\SeoKataKunci;
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
            'gsc'        => ['tersambung' => $this->gsc->tersambung(), 'email' => $this->gsc->emailAkun(), 'status' => $this->gsc->status()],
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

        SeoKataKunci::create(['kata' => $kata, 'seo_jenis_id' => $d['seo_jenis_id'] ?? null, 'niat' => $d['niat'] ?? null, 'sumber' => 'manual']);
        return back()->with('success', "\"$kata\" ditambahkan.");
    }

    public function ubah(Request $request, SeoKataKunci $kata)
    {
        $d = $request->validate([
            'seo_jenis_id' => 'nullable|exists:seo_jenis,id',
            'niat'         => ['nullable', Rule::in(array_keys(SeoKataKunci::NIAT))],
            'aktif'        => 'nullable|boolean',
        ]);
        $kata->update(array_intersect_key($d, $request->all()));
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

    public function tarikGsc()
    {
        if (!$this->gsc->tersambung()) return back()->with('error', 'Search Console belum disambungkan.');
        try {
            $h = $this->gsc->tarik();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', "{$h['baris']} kata pencarian ditarik dari Search Console, {$h['baru']} kata baru.");
    }
}
