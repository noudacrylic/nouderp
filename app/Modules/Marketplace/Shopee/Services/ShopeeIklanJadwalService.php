<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Modules\Marketplace\Shopee\Models\ShopeeIklan;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklanKelompok as Kelompok;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Notifications\Services\TelegramNotifier;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Jadwal & rotasi kelompok iklan Shopee.
 *
 * Tiap jam ditentukan iklan anggota mana yang SEHARUSNYA tayang:
 *  - jam ramai (dan belanja kelompok hari ini belum mencapai batas) → semua anggota yang layak;
 *  - jam sepi → `aktif_sepi` iklan, bergiliran tiap `rotasi_jam` jam (00–02, 03–05, … untuk 3 jam).
 * Layak = status berjalan/dijeda dan stok listing tidak 0. Giliran memakai smooth weighted
 * round-robin berbobot ROAS 14 hari yang dihaluskan ke rata-rata kelompok, jadi semua iklan
 * kebagian, yang ROAS-nya bagus lebih sering. Pilihan awal digeser per tanggal agar urutan
 * tidak sama tiap hari.
 *
 * Iklan ber-jadwal manual (klik di grid) tayang persis di jam pilihannya dan tidak ikut
 * giliran; `aktif_sepi` berlaku untuk anggota otomatis saja.
 *
 * Mode bayangan: rencana dibandingkan dengan status simulasi kelompok (bukan status asli),
 * lalu dicatat di shopee_iklan_aksi tanpa memanggil Shopee. Mode otomatis menjeda/melanjutkan
 * sungguhan lewat ShopeeIklanAksiService (teruji ke Shopee asli 21 Nov 2026).
 */
class ShopeeIklanJadwalService
{
    /** Penghalus ROAS: iklan dengan belanja kecil ditarik ke rata-rata kelompok seolah berbelanja Rp20 rb. */
    private const PENGHALUS_RP = 20000;

    private const HARI_SKOR = 14;

    public function __construct(
        private ShopeeClient $client,
        private ShopeeIklanAksiService $aksi,
        private TelegramNotifier $telegram,
    ) {}

    /**
     * Bobot & data anggota kelompok yang layak, urut bobot terbesar.
     *
     * @return Collection<int, array{iklan:ShopeeIklan, bobot:float, roas:?float, stok:?int, layak:bool, alasan:?string}>
     */
    public function anggota(Kelompok $k): Collection
    {
        $iklan = $k->iklan()->orderBy('campaign_id')->get();
        if ($iklan->isEmpty()) {
            return collect();
        }

        $dari  = now()->subDays(self::HARI_SKOR)->toDateString();
        $kin   = DB::table('shopee_iklan_jam')->where('env', $k->env)
            ->whereIn('campaign_id', $iklan->pluck('campaign_id'))
            ->where('tanggal', '>=', $dari)->where('tanggal', '<', now()->toDateString())
            ->groupBy('campaign_id')->selectRaw('campaign_id, SUM(biaya) biaya, SUM(gmv) gmv')
            ->get()->keyBy('campaign_id');

        $totalBiaya = (float) $kin->sum('biaya');
        $rataRoas   = $totalBiaya > 0 ? (float) $kin->sum('gmv') / $totalBiaya : 0.0;

        $stokPerItem = ShopeeItem::where('env', $k->env)
            ->whereIn('item_id', $iklan->flatMap(fn ($i) => $i->item_ids ?? [])->unique()->values())
            ->groupBy('item_id')->selectRaw('item_id, SUM(stok) stok')->pluck('stok', 'item_id');

        return $iklan->map(function (ShopeeIklan $i) use ($k, $kin, $rataRoas, $stokPerItem) {
            $r     = $kin[$i->campaign_id] ?? null;
            $biaya = (float) ($r->biaya ?? 0);
            $gmv   = (float) ($r->gmv ?? 0);
            $bobot = $rataRoas > 0 ? ($gmv + $rataRoas * self::PENGHALUS_RP) / ($biaya + self::PENGHALUS_RP) : 1.0;

            $stok = null;
            foreach ($i->item_ids ?? [] as $id) {
                if (isset($stokPerItem[$id])) {
                    $stok = ($stok ?? 0) + (int) $stokPerItem[$id];
                }
            }

            $alasan = match (true) {
                !$i->bisaDiatur() => 'iklan ' . strtolower($i->labelStatus()),
                $stok === 0       => 'stok habis',
                default           => null,
            };

            return [
                'iklan'  => $i,
                'bobot'  => max(0.1, $bobot),
                'roas'   => $biaya > 0 ? $gmv / $biaya : null,
                'stok'   => $stok,
                'layak'  => $alasan === null,
                'alasan' => $alasan,
                'manual' => $k->jamManual($i->campaign_id),
            ];
        })->sortByDesc('bobot')->values();
    }

    /** Belanja anggota kelompok hari ini (dari data per jam terakhir). */
    public function belanjaHariIni(Kelompok $k): float
    {
        $ids = $k->iklan()->pluck('campaign_id');

        return $ids->isEmpty() ? 0.0 : (float) DB::table('shopee_iklan_jam')->where('env', $k->env)
            ->whereIn('campaign_id', $ids)->where('tanggal', now()->toDateString())->sum('biaya');
    }

    /**
     * Rencana sehari penuh: jam → daftar campaign_id yang tayang. Batas belanja tidak
     * diperhitungkan (belanja jam mendatang belum diketahui).
     *
     * @return array<int, array<int, int>>
     */
    public function rencanaHari(Kelompok $k, Carbon $tanggal, ?Collection $anggota = null): array
    {
        $anggota ??= $this->anggota($k);
        $hasil = [];
        for ($j = 0; $j < 24; $j++) {
            $hasil[$j] = $this->aktifPada($k, $tanggal->copy()->setTime($j, 0), $anggota, false);
        }

        return $hasil;
    }

    /**
     * campaign_id anggota yang seharusnya tayang pada jam $waktu.
     *
     * @return array<int, int>
     */
    public function aktifPada(Kelompok $k, Carbon $waktu, ?Collection $anggota = null, bool $hitungBatas = true): array
    {
        $anggota ??= $this->anggota($k);
        $jam   = (int) $waktu->format('G');
        $layak = $anggota->where('layak', true);

        // Jadwal manual diikuti persis (stok habis / iklan selesai tetap tidak tayang).
        $manual = $layak->filter(fn ($a) => $a['manual'] !== null && in_array($jam, $a['manual'], true))
            ->map(fn ($a) => $a['iklan']->campaign_id)->values()->all();

        $otomatis = $layak->filter(fn ($a) => $a['manual'] === null)->values();
        if ($otomatis->isEmpty()) {
            return $manual;
        }

        $lewatBatas = $hitungBatas && $k->batas_belanja !== null && (float) $k->batas_belanja > 0
            && $this->belanjaHariIni($k) >= (float) $k->batas_belanja;

        $auto = in_array($jam, $k->jamRamai(), true) && !$lewatBatas
            ? $otomatis->map(fn ($a) => $a['iklan']->campaign_id)->all()
            : $this->giliran($otomatis, max(1, $k->aktif_sepi), max(1, $k->rotasi_jam), $waktu);

        return array_values(array_merge($manual, $auto));
    }

    /**
     * Smooth weighted round-robin dari awal hari sampai giliran jam $waktu. Tiap giliran
     * semua kredit naik sebesar bobotnya, N kredit terbesar terpilih lalu dikurangi
     * total bobot / N. Seri dipecah dengan urutan yang digeser per tanggal.
     *
     * @param  Collection<int, array{iklan:ShopeeIklan, bobot:float}>  $layak
     * @return array<int, int>
     */
    public function giliran(Collection $layak, int $n, int $rotasi, Carbon $waktu): array
    {
        $jumlah = $layak->count();
        if ($n >= $jumlah) {
            return $layak->map(fn ($a) => $a['iklan']->campaign_id)->all();
        }

        $bobot  = $layak->pluck('bobot')->all();
        $total  = array_sum($bobot);
        $geser  = $waktu->dayOfYear % $jumlah;
        $kredit = array_fill(0, $jumlah, 0.0);
        $slot   = intdiv((int) $waktu->format('G'), $rotasi);

        $pilih = [];
        for ($s = 0; $s <= $slot; $s++) {
            foreach ($bobot as $i => $b) {
                $kredit[$i] += $b;
            }
            $urut = range(0, $jumlah - 1);
            usort($urut, fn ($a, $b) => [$kredit[$b], ($a - $geser + $jumlah) % $jumlah] <=> [$kredit[$a], ($b - $geser + $jumlah) % $jumlah]);
            $pilih = array_slice($urut, 0, $n);
            foreach ($pilih as $i) {
                $kredit[$i] -= $total / $n;
            }
        }

        sort($pilih);

        return array_map(fn ($i) => $layak[$i]['iklan']->campaign_id, $pilih);
    }

    /**
     * Saran jam ramai dari data 14 hari anggota: jam yang ROAS-nya ≥ rata-rata kelompok
     * dan punya belanja. Tanpa data: 08–21.
     *
     * @param  array<int, int>  $campaignIds
     * @return array<int, int>
     */
    public function saranJamRamai(array $campaignIds, string $env): array
    {
        $rows = $campaignIds ? DB::table('shopee_iklan_jam')->where('env', $env)->whereIn('campaign_id', $campaignIds)
            ->where('tanggal', '>=', now()->subDays(self::HARI_SKOR)->toDateString())->where('tanggal', '<', now()->toDateString())
            ->groupBy('jam')->selectRaw('jam, SUM(biaya) biaya, SUM(gmv) gmv')->get() : collect();

        $biaya = (float) $rows->sum('biaya');
        if ($biaya <= 0) {
            return range(8, 21);
        }
        $rata = (float) $rows->sum('gmv') / $biaya;

        return $rows->filter(fn ($r) => $r->biaya > 0 && $r->gmv / $r->biaya >= $rata)
            ->pluck('jam')->map(fn ($j) => (int) $j)->sort()->values()->all();
    }

    /**
     * Jalankan jadwal untuk jam $waktu (dipanggil tiap jam).
     *  - bayangan : dibandingkan dengan status simulasi, hanya dicatat.
     *  - otomatis : status asli dibaca dulu dari Shopee, lalu jeda/lanjut sungguhan.
     * Lanjut dikerjakan sebelum jeda; bila ada lanjut yang gagal, jeda kelompok itu ditunda
     * jam ini supaya kelompok tidak mati total. Batas aksi harian dihitung terpisah untuk
     * aksi nyata dan bayangan. Kegagalan dikirim ke Telegram approver.
     *
     * @return array{kelompok:int, aksi:int, dilewati:int, gagal:int}
     */
    public function jalankan(?Carbon $waktu = null): array
    {
        $waktu ??= now();
        $env   = $this->client->env();
        $maks  = (int) config('services.shopee.iklan_maks_aksi', 100);
        $hari  = DB::table('shopee_iklan_aksi')->where('env', $env)->where('created_at', '>=', $waktu->copy()->startOfDay());
        $sudah = [
            'bayangan' => (clone $hari)->where('mode', 'bayangan')->where('hasil', 'bayangan')->count(),
            'nyata'    => (clone $hari)->where('mode', '!=', 'bayangan')->whereIn('hasil', ['berhasil', 'gagal'])->count(),
        ];

        $hasil = ['kelompok' => 0, 'aksi' => 0, 'dilewati' => 0, 'gagal' => 0];
        $laporGagal = [];
        $shop = null;

        foreach (Kelompok::where('env', $env)->whereIn('mode', ['bayangan', 'otomatis'])->orderBy('id')->get() as $k) {
            $nyata = $k->mode === 'otomatis';
            if ($nyata) {
                $shop ??= ShopeeShop::aktif();
                if (!$shop || $shop->perluHubungkanUlang()) {
                    $laporGagal[] = "{$k->nama}: toko Shopee belum terhubung";
                    continue;
                }
                try {
                    $this->aksi->segarkanStatus($shop, $k->iklan()->get());
                } catch (ShopeeException $e) {
                    $laporGagal[] = "{$k->nama}: gagal membaca status — {$e->getMessage()}";
                    continue;
                }
            }

            $hasil['kelompok']++;
            $anggota = $this->anggota($k);
            $target  = array_flip($this->aktifPada($k, $waktu, $anggota));
            $alasan  = $this->alasan($k, $waktu);
            $sim     = $k->status_simulasi ?? [];

            $rencana = [];
            foreach ($anggota as $a) {
                $i = $a['iklan'];
                if (!$i->bisaDiatur()) {
                    continue;
                }
                $kini = !$nyata && array_key_exists((string) $i->campaign_id, $sim)
                    ? (bool) $sim[(string) $i->campaign_id]
                    : $i->status === 'ongoing';
                $mau = isset($target[$i->campaign_id]);
                if ($kini !== $mau) {
                    $why = $a['alasan'] ?? ($a['manual'] !== null ? 'jadwal manual' : $alasan);
                    $rencana[] = [$i, $mau ? 'lanjut' : 'jeda', $why];
                }
                $sim[(string) $i->campaign_id] = $kini;
            }
            // Lanjutkan dulu baru jeda: bila batas aksi tercapai di tengah, kelompok tidak mati total.
            usort($rencana, fn ($a, $b) => ($a[1] === 'lanjut' ? 0 : 1) <=> ($b[1] === 'lanjut' ? 0 : 1));

            $lanjutGagal = false;
            $kunci = $nyata ? 'nyata' : 'bayangan';
            foreach ($rencana as [$i, $aksi, $why]) {
                $lewati = match (true) {
                    $sudah[$kunci] >= $maks          => 'batas aksi harian tercapai',
                    $aksi === 'jeda' && $lanjutGagal => 'ada iklan yang gagal dilanjutkan — jeda ditunda',
                    default                          => null,
                };
                if ($lewati) {
                    $this->catat($env, $k->id, $i->campaign_id, $aksi, $k->mode, 'dilewati', $lewati, $waktu);
                    $hasil['dilewati']++;
                    continue;
                }

                $sudah[$kunci]++;
                if (!$nyata) {
                    $this->catat($env, $k->id, $i->campaign_id, $aksi, 'bayangan', 'bayangan', $why, $waktu);
                    $sim[(string) $i->campaign_id] = $aksi === 'lanjut';
                    $hasil['aksi']++;
                    continue;
                }

                $r = $this->aksi->ubah($shop, $i, $aksi, 'otomatis', $k->id, $why);
                if ($r['ok']) {
                    $hasil['aksi']++;
                } else {
                    $hasil['gagal']++;
                    $lanjutGagal = $lanjutGagal || $aksi === 'lanjut';
                    $laporGagal[] = "{$k->nama}: {$aksi} \"" . mb_substr((string) $i->nama, 0, 50) . "\" gagal — {$r['pesan']} (status " . ($r['status'] ?? '?') . ')';
                }
            }

            $k->update(['status_simulasi' => $nyata ? null : $sim, 'dijalankan_at' => $waktu]);
        }

        if ($laporGagal) {
            try {
                $this->telegram->notifyApprovers('⚠️ Jadwal iklan Shopee jam ' . $waktu->format('H.i') . ":\n- " . implode("\n- ", array_slice($laporGagal, 0, 10)));
            } catch (\Throwable) {
                // Telegram hanya kabar; jadwal tetap jalan.
            }
        }

        return $hasil;
    }

    private function catat(string $env, int $kelompokId, int $campaignId, string $aksi, string $mode, string $hasil, string $alasan, Carbon $waktu): void
    {
        DB::table('shopee_iklan_aksi')->insert([
            'env' => $env, 'kelompok_id' => $kelompokId, 'campaign_id' => $campaignId, 'aksi' => $aksi,
            'mode' => $mode, 'hasil' => $hasil, 'alasan' => mb_substr($alasan, 0, 100),
            'created_at' => $waktu, 'updated_at' => $waktu,
        ]);
    }

    private function alasan(Kelompok $k, Carbon $waktu): string
    {
        $jam = (int) $waktu->format('G');
        if (in_array($jam, $k->jamRamai(), true)) {
            return $k->batas_belanja && $this->belanjaHariIni($k) >= (float) $k->batas_belanja
                ? 'batas belanja kelompok tercapai' : 'jam ramai';
        }

        return 'jam sepi — giliran ' . sprintf('%02d', intdiv($jam, max(1, $k->rotasi_jam)) * max(1, $k->rotasi_jam)) . '.00';
    }
}
