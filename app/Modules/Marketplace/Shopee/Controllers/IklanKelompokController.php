<?php

namespace App\Modules\Marketplace\Shopee\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklan;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklanKelompok as Kelompok;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use App\Modules\Marketplace\Shopee\Services\ShopeeIklanAksiService;
use App\Modules\Marketplace\Shopee\Services\ShopeeIklanJadwalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Penjualan › Iklan Shopee › Kelompok & Jadwal. */
class IklanKelompokController extends Controller
{
    public const ROTASI = [1, 2, 3, 4, 6, 8, 12];

    public function __construct(private ShopeeClient $client, private ShopeeIklanJadwalService $jadwal) {}

    public function index()
    {
        $env      = $this->client->env();
        $kelompok = Kelompok::where('env', $env)->withCount('iklan')->orderBy('nama')->get();
        $ringkas  = $kelompok->mapWithKeys(function (Kelompok $k) {
            $rencana = $this->jadwal->rencanaHari($k, now()->startOfDay());

            return [$k->id => ['aksi' => $this->hitungAksi($rencana), 'aktif_kini' => count($rencana[(int) now()->format('G')])]];
        });

        $log = DB::table('shopee_iklan_aksi as a')
            ->leftJoin('shopee_iklan as i', fn ($j) => $j->on('i.campaign_id', '=', 'a.campaign_id')->on('i.env', '=', 'a.env'))
            ->leftJoin('shopee_iklan_kelompok as k', 'k.id', '=', 'a.kelompok_id')
            ->where('a.env', $env)->orderByDesc('a.id')->limit(100)
            ->get(['a.*', 'i.nama as nama_iklan', 'k.nama as nama_kelompok']);

        return view('erp.sales.iklan-shopee.kelompok.index', [
            'kelompok'   => $kelompok,
            'ringkas'    => $ringkas,
            'log'        => $log,
            'maksAksi'   => (int) config('services.shopee.iklan_maks_aksi', 100),
            'calonUji'   => ShopeeIklan::where('env', $env)->where('status', 'ongoing')->orderBy('modal_harian')->orderBy('nama')->get(['campaign_id', 'nama', 'modal_harian']),
            'aksiHariIni' => DB::table('shopee_iklan_aksi')->where('env', $env)->where('created_at', '>=', now()->startOfDay())
                ->whereIn('hasil', ['bayangan', 'berhasil'])->count(),
        ]);
    }

    public function create()
    {
        $k = new Kelompok(['mode' => 'bayangan', 'aktif_sepi' => 2, 'rotasi_jam' => 3]);

        return $this->form($k);
    }

    public function store(Request $request)
    {
        $k = new Kelompok(['env' => $this->client->env()]);
        $this->simpan($request, $k);

        return redirect()->route('sales.iklan-shopee.kelompok.edit', $k)->with('success', 'Kelompok iklan dibuat.');
    }

    public function edit(Kelompok $kelompok)
    {
        return $this->form($kelompok);
    }

    public function update(Request $request, Kelompok $kelompok)
    {
        $this->simpan($request, $kelompok);

        return back()->with('success', 'Kelompok iklan disimpan.');
    }

    /**
     * Simpan jadwal manual dari grid. `baris[campaign_id]` = jam tayang dipisah koma (boleh kosong
     * = tidak pernah tayang); `otomatis` = campaign_id yang dikembalikan ke giliran otomatis.
     */
    public function jadwalManual(Request $request, Kelompok $kelompok)
    {
        $anggota = $kelompok->iklan()->pluck('campaign_id')->map(fn ($id) => (string) $id)->all();
        $manual  = array_intersect_key($kelompok->jadwal_manual ?? [], array_flip($anggota));

        foreach ((array) $request->input('baris', []) as $cid => $csv) {
            if (!in_array((string) $cid, $anggota, true)) {
                continue;
            }
            $jam = array_values(array_unique(array_filter(
                array_map('intval', array_filter(explode(',', (string) $csv), fn ($x) => trim($x) !== '')),
                fn ($j) => $j >= 0 && $j <= 23,
            )));
            sort($jam);
            $manual[(string) $cid] = $jam;
        }
        if ($request->filled('otomatis')) {
            unset($manual[(string) $request->input('otomatis')]);
        }

        $kelompok->update(['jadwal_manual' => $manual ?: null]);

        return back()->with('success', $request->filled('otomatis') ? 'Iklan dikembalikan ke giliran otomatis.' : 'Jadwal manual disimpan.');
    }

    /**
     * Uji perintah tulis: jeda satu iklan berjalan, tunggu sebentar, lanjutkan lagi. Hasil per
     * langkah dibaca balik dari Shopee. Dasar sebelum mode Otomatis dibuka.
     */
    public function ujiJeda(Request $request, ShopeeIklanAksiService $aksi)
    {
        $iklan = ShopeeIklan::where('env', $this->client->env())->where('campaign_id', (int) $request->input('campaign_id'))->firstOrFail();
        $shop  = ShopeeShop::aktif();
        if (!$shop || $shop->perluHubungkanUlang()) {
            return back()->with('error', 'Toko Shopee belum terhubung.');
        }

        try {
            $awal = $aksi->statusDiShopee($shop, $iklan);
        } catch (ShopeeException $e) {
            return back()->with('error', 'Gagal membaca status iklan: ' . $e->getMessage());
        }
        if ($awal !== 'ongoing') {
            return back()->with('error', "Pilih iklan yang sedang berjalan (status iklan ini di Shopee: {$awal}).");
        }

        $jeda = $aksi->ubah($shop, $iklan, 'jeda', 'uji', null, 'uji jeda & lanjut');
        sleep(3);
        $lanjut = $aksi->ubah($shop, $iklan, 'lanjut', 'uji', null, 'uji jeda & lanjut');

        $ringkas = "Jeda: {$jeda['pesan']} → {$jeda['status']}. Lanjut: {$lanjut['pesan']} → {$lanjut['status']}.";
        if ($jeda['ok'] && $lanjut['ok']) {
            return back()->with('success', 'Uji berhasil — iklan sempat dijeda lalu berjalan lagi. ' . $ringkas);
        }
        if ($lanjut['status'] !== 'ongoing') {
            return back()->with('error', "PERHATIAN: iklan \"{$iklan->nama}\" belum berjalan lagi — lanjutkan manual di Seller Centre. {$ringkas}");
        }

        return back()->with('warning', 'Uji belum berhasil sepenuhnya. ' . $ringkas);
    }

    public function destroy(Kelompok $kelompok)
    {
        $kelompok->iklan()->update(['kelompok_id' => null]);
        $kelompok->delete();

        return redirect()->route('sales.iklan-shopee.kelompok.index')->with('success', 'Kelompok dihapus. Iklan anggotanya tidak diubah.');
    }

    private function form(Kelompok $k)
    {
        $env = $this->client->env();
        // Calon anggota: iklan yang masih bisa diatur dan belum masuk kelompok lain.
        $calon = ShopeeIklan::where('env', $env)
            ->where(fn ($q) => $q->whereIn('status', ['ongoing', 'paused'])->orWhere('kelompok_id', $k->id ?: -1))
            ->where(fn ($q) => $q->whereNull('kelompok_id')->orWhere('kelompok_id', $k->id ?: -1))
            ->orderBy('nama')->get();

        $anggota = $k->exists ? $this->jadwal->anggota($k) : collect();
        $rencana = $k->exists ? $this->jadwal->rencanaHari($k, now()->startOfDay(), $anggota) : [];
        $idSaran = $k->exists && $anggota->isNotEmpty() ? $anggota->map(fn ($a) => $a['iklan']->campaign_id)->all() : $calon->pluck('campaign_id')->all();

        $log = $k->exists ? DB::table('shopee_iklan_aksi')->where('kelompok_id', $k->id)->orderByDesc('id')->limit(60)->get() : collect();

        return view('erp.sales.iklan-shopee.kelompok.form', [
            'k'          => $k,
            'calon'      => $calon,
            'anggota'    => $anggota,
            'rencana'    => $rencana,
            'aksiPerHari' => $rencana ? $this->hitungAksi($rencana) : 0,
            'saranJam'   => $this->jadwal->saranJamRamai($idSaran, $env),
            'belanja'    => $k->exists ? $this->jadwal->belanjaHariIni($k) : 0,
            'log'        => $log,
            'namaIklan'  => $calon->pluck('nama', 'campaign_id'),
        ]);
    }

    private function simpan(Request $request, Kelompok $k): void
    {
        $request->merge(['batas_belanja' => $request->filled('batas_belanja') ? clean_number($request->input('batas_belanja')) : null]);
        $data = $request->validate([
            'nama'          => 'required|string|max:100',
            'mode'          => ['required', Rule::in(['mati', 'bayangan', 'otomatis'])],
            'jam_ramai'     => 'array',
            'jam_ramai.*'   => 'integer|between:0,23',
            'aktif_sepi'    => 'required|integer|between:1,20',
            'rotasi_jam'    => ['required', 'integer', Rule::in(self::ROTASI)],
            'batas_belanja' => 'nullable|numeric|min:0',
            'anggota'       => 'array',
            'anggota.*'     => 'integer',
        ]);

        $modeLama = $k->mode;
        $k->fill([
            'nama'          => $data['nama'],
            'mode'          => $data['mode'],
            'jam_ramai'     => array_values(array_unique(array_map('intval', $data['jam_ramai'] ?? []))),
            'aktif_sepi'    => $data['aktif_sepi'],
            'rotasi_jam'    => $data['rotasi_jam'],
            'batas_belanja' => $data['batas_belanja'] ?: null,
        ]);
        // Simulasi dimulai ulang dari status asli setiap kali mode berganti.
        if ($modeLama !== $k->mode) {
            $k->status_simulasi = null;
        }
        $k->save();

        $ids = array_map('intval', $data['anggota'] ?? []);
        DB::transaction(function () use ($k, $ids) {
            ShopeeIklan::where('kelompok_id', $k->id)->whereNotIn('campaign_id', $ids ?: [0])->update(['kelompok_id' => null]);
            ShopeeIklan::where('env', $k->env)->whereIn('campaign_id', $ids)
                ->where(fn ($q) => $q->whereNull('kelompok_id')->orWhere('kelompok_id', $k->id))
                ->update(['kelompok_id' => $k->id]);
        });

        // Jadwal manual milik iklan yang sudah keluar dari kelompok dibuang.
        $tetap = $k->iklan()->pluck('campaign_id')->map(fn ($id) => (string) $id)->all();
        $manual = array_intersect_key($k->jadwal_manual ?? [], array_flip($tetap));
        if ($manual !== ($k->jadwal_manual ?? [])) {
            $k->update(['jadwal_manual' => $manual ?: null]);
        }
    }

    /** Jumlah pergantian status sepanjang hari menurut rencana (termasuk awal hari dari status jam 23). */
    private function hitungAksi(array $rencana): int
    {
        $n = 0;
        for ($j = 0; $j < 24; $j++) {
            $sebelum = $rencana[($j + 23) % 24];
            $n += count(array_diff($rencana[$j], $sebelum)) + count(array_diff($sebelum, $rencana[$j]));
        }

        return $n;
    }
}
