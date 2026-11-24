<?php

namespace App\Modules\Production\Services;

use App\Models\ProductionSetting;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Penilaian kinerja divisi produksi per OP (Assembling dulu).
 *
 * Tiap langkah OP selesai diberi JATAH waktu = Σ(qty output utama × waktu standar SKU).
 * Dibanding menit timer (rasio = timer ÷ jatah):
 *   - rasio ≤ 1 − pita  → lebih cepat  = 2 poin
 *   - dalam ±pita       → sesuai       = 1 poin
 *   - rasio > 1 + pita  → lebih lambat = 0 poin
 * Nilai bulanan = rata-rata poin OP (1,0 = normal), dipetakan ke tingkat bonus.
 *
 * OP yang rasionya di luar 1/faktor_cek .. faktor_cek ditandai "perlu dicek" (biasanya timer
 * lupa dinyalakan/dijeda, atau ada yang janggal) — poinnya DITAHAN, tidak ikut rata-rata.
 * OP perbaikan/garansi, OP berisi produk custom bebas (CS1..CS6), dan OP yang salah satu
 * produknya belum punya standar tidak dinilai. OP custom lain (mis. BC custom) IKUT dinilai.
 *
 * Parameter (pita, faktor_cek, min_op, tingkat) diatur owner dari Dashboard Penilaian dan
 * disimpan di production_settings.penilaian_param; DEFAULT dipakai bila belum pernah diatur.
 *
 * Mode simulasi: SKU yang belum punya standar disetujui memakai usulan rata-rata (≥ MIN_SAMPEL)
 * dari pelaksana lama — supaya hasil bisa dilihat sebelum owner menyetujui angka apa pun.
 */
class PenilaianKinerjaService
{
    public const DEFAULT = [
        'pita'       => 0.10, // "sesuai" ±10%
        'faktor_cek' => 2.0,  // di luar ½×–2× jatah → perlu dicek
        'min_op'     => 10,   // di bawah ini nilai ditandai "data belum cukup"
        'bobot'      => 'op', // op = tiap OP sama berat; jatah = rata-rata poin ditimbang jatah
        // Toleransi merk akrilik: jatah OP × faktor merk tersulit di OP itu (dipilih CNC saat Mulai).
        // Mis. ['MAG' => 1.25] → OP berbahan MAG dapat jatah 25% lebih lama. Kosong = semua 1,0.
        'faktor_merk' => [],
        'tingkat'    => [     // urut dari ambang tertinggi; bonus rupiah per bulan
            ['min' => 1.4, 'label' => 'Istimewa', 'bonus' => 550000],
            ['min' => 1.0, 'label' => 'Normal',   'bonus' => 440000],
            ['min' => 0.7, 'label' => 'Kurang',   'bonus' => 300000],
            ['min' => 0.0, 'label' => 'Rendah',   'bonus' => 200000],
        ],
    ];

    /** @var array<int, Collection> usulan median per divisi (mahal, dihitung sekali) */
    private array $medianCache = [];

    private ?array $param = null;

    public function __construct(private WaktuStandarService $ws) {}

    /** Parameter berlaku: tersimpan digabung default. */
    public function param(): array
    {
        if ($this->param !== null) return $this->param;
        $simpan = ProductionSetting::first()?->penilaian_param ?: [];

        return $this->param = self::rapikan(array_merge(self::DEFAULT, $simpan));
    }

    /** Untuk uji/what-if: pakai parameter lain tanpa menyimpan. */
    public function pakaiParam(array $param): static
    {
        $this->param = self::rapikan(array_merge(self::DEFAULT, $param));

        return $this;
    }

    /** Batasi angka ke rentang masuk akal & urutkan tingkat dari ambang tertinggi. */
    public static function rapikan(array $p): array
    {
        $tingkat = collect($p['tingkat'] ?? self::DEFAULT['tingkat'])
            ->map(fn ($t) => [
                'min'   => round(max(0, min(2, (float) ($t['min'] ?? 0))), 2),
                'label' => trim((string) ($t['label'] ?? '')) ?: 'Tingkat',
                'bonus' => (int) max(0, (float) ($t['bonus'] ?? 0)),
            ])
            ->sortByDesc('min')->values()->all();
        // Tingkat terbawah selalu mulai dari 0 supaya setiap nilai punya tingkat.
        if ($tingkat) $tingkat[count($tingkat) - 1]['min'] = 0.0;

        return [
            'pita'       => round(max(0, min(0.5, (float) $p['pita'])), 3),
            'faktor_cek' => round(max(1.2, min(10, (float) $p['faktor_cek'])), 2),
            'min_op'     => (int) max(0, min(100, (int) $p['min_op'])),
            'bobot'      => ($p['bobot'] ?? 'op') === 'jatah' ? 'jatah' : 'op',
            'tingkat'    => $tingkat ?: self::DEFAULT['tingkat'],
            // Hanya faktor ≠ 1 yang disimpan; batas 0,5–2 supaya salah ketik tak merusak nilai.
            'faktor_merk' => collect((array) ($p['faktor_merk'] ?? []))
                ->mapWithKeys(fn ($f, $m) => [mb_substr(trim((string) $m), 0, 50) => round(max(0.5, min(2, (float) $f)), 2)])
                ->filter(fn ($f, $m) => $m !== '' && abs($f - 1) > 1e-9)
                ->all(),
        ];
    }

    /**
     * Faktor jatah OP dari merk akrilik: rata-rata tertimbang lembar per merk ($jumlah, mis.
     * MAG 2 + NN 1 → (2×1,25 + 1×1)/3). Data lama tanpa jumlah → merk tersulit. Tanpa merk = 1.
     */
    public function faktorMerk(?array $merk, ?array $jumlah = null): float
    {
        $f = $this->param()['faktor_merk'];
        $jumlah = array_filter((array) $jumlah, fn ($n) => (int) $n > 0);
        if ($jumlah) {
            $total = array_sum($jumlah);
            return array_sum(array_map(fn ($m, $n) => $n * (float) ($f[$m] ?? 1), array_keys($jumlah), $jumlah)) / $total;
        }

        return (float) collect($merk ?? [])->map(fn ($m) => (float) ($f[$m] ?? 1))->max() ?: 1.0;
    }

    public function simpanParam(array $param): array
    {
        $bersih = self::rapikan(array_merge(self::DEFAULT, $param));
        $s = ProductionSetting::first() ?? new ProductionSetting();
        $s->penilaian_param = $bersih;
        $s->save();
        $this->param = $bersih;

        return $bersih;
    }

    /**
     * Nilai satu bulan untuk satu divisi.
     *
     * @return array{op: Collection, pelaksana: Collection, tanpa_standar: Collection, standar_simulasi: int}
     */
    public function bulan(int $departmentId, Carbon $bulan, bool $simulasi = false): array
    {
        $ops = $this->opBulan($departmentId, $bulan, $simulasi);
        $dinilai = $ops['op'];

        return [
            'op'               => $dinilai,
            'pelaksana'        => $this->ringkasPelaksana($departmentId, $dinilai, $ops['tanpa_standar']),
            'tanpa_standar'    => $ops['tanpa_standar'],
            'standar_simulasi' => $ops['standar_simulasi'],
        ];
    }

    /** @var array<string, array> hasil opBulan per (divisi, bulan, simulasi, param) dalam satu permintaan */
    private array $memoBulan = [];

    /** Daftar OP satu bulan beserta jatah & rasio; status dinilai/cek mengikuti param(). */
    public function opBulan(int $departmentId, Carbon $bulan, bool $simulasi = false): array
    {
        $kunci = $departmentId . '|' . $bulan->format('Y-m') . '|' . (int) $simulasi . '|' . md5(json_encode($this->param()));

        return $this->memoBulan[$kunci] ??= $this->hitungOpBulan($departmentId, $bulan, $simulasi);
    }

    private function hitungOpBulan(int $departmentId, Carbon $bulan, bool $simulasi): array
    {
        $awal  = $bulan->copy()->startOfMonth();
        $akhir = $awal->copy()->addMonth();

        $standar = $this->petaStandar($departmentId, $akhir->copy()->subDay(), $simulasi);

        $steps = ProductionOrderStep::query()
            ->with([
                'timeLogs',
                'executors:id,name',
                'productionOrder:id,order_number,type,merk_bahan,merk_jumlah',
                'productionOrder.outputs' => fn ($q) => $q->where('output_type', 'main'),
                'productionOrder.outputs.product:id,sku,name',
            ])
            ->where('department_id', $departmentId)
            ->where('status', 'completed')
            ->where('is_qc', false)
            ->whereNull('sample_excluded_reason')
            ->where('completed_at', '>=', $awal)
            ->where('completed_at', '<', $akhir)
            ->whereHas('productionOrder', fn ($q) => $q->whereNotIn('type', WaktuStandarService::TIPE_DIKECUALIKAN))
            ->orderBy('completed_at')
            ->get();

        $ops = collect();
        $tanpaStandar = collect();
        foreach ($steps as $s) {
            $hasil = $this->nilaiLangkah($s, $standar);
            if ($hasil['status'] === 'tanpa_standar') {
                $tanpaStandar->push($hasil);
            } elseif ($hasil['status'] !== 'kosong') {
                $ops->push($hasil);
            }
        }

        return [
            'op'               => $ops,
            'tanpa_standar'    => $tanpaStandar,
            'standar_simulasi' => $standar->where('sumber', 'simulasi')->count(),
        ];
    }

    /**
     * Waktu standar per produk pada tanggal tertentu: product_id => ['menit', 'sumber'].
     * sumber = disetujui | simulasi (usulan median, hanya bila $simulasi).
     */
    public function petaStandar(int $departmentId, Carbon $tanggal, bool $simulasi): Collection
    {
        $peta = $this->ws->berlakuSemua($departmentId, $tanggal)
            ->map(fn ($st) => ['menit' => (float) $st->minutes_per_unit, 'sumber' => 'disetujui']);

        if ($simulasi) {
            $this->medianCache[$departmentId] ??= $this->ws
                ->usulan($departmentId, now()->subDays(150)->startOfDay(), $this->ws->pelaksanaLama($departmentId)->all())
                ->filter(fn ($u) => $u['cukup'] && $u['rata'] > 0)
                ->map(fn ($u) => (float) $u['rata']);

            foreach ($this->medianCache[$departmentId] as $pid => $median) {
                if (!$peta->has($pid)) {
                    $peta[$pid] = ['menit' => $median, 'sumber' => 'simulasi'];
                }
            }
        }

        return $peta;
    }

    /**
     * Jatah waktu OP = Σ (pcs × waktu standar) × faktor merk akrilik. Dipakai penilaian DAN target
     * di kartu Assembling — satu rumus supaya angka yang dilihat karyawan sama dengan yang dinilai.
     * Butuh relasi outputs (main) + product termuat. Custom bebas CS → kosong (tak bertarget).
     *
     * @return array{jatah: float, rincian: array, kurang: array, merk: array, mj: array, faktor: float}
     */
    public function hitungJatah(ProductionOrder $order, Collection $standar): array
    {
        $outs = $order->outputs->where('output_type', 'main');
        if ($outs->contains(fn ($o) => WaktuStandarService::skuCustomBebas($o->product?->sku))) {
            $outs = collect();
        }

        $rincian = [];
        $jatah = 0.0;
        $kurang = [];
        foreach ($outs as $o) {
            $qty = (float) ($o->qty_produced > 0 ? $o->qty_produced : $o->qty_planned);
            if ($qty <= 0) continue;
            $st = $standar->get((int) $o->product_id);
            if (!$st) {
                $kurang[] = $o->product?->sku ?? ('#' . $o->product_id);
                continue;
            }
            $jatah += $qty * $st['menit'];
            $rincian[] = ['sku' => $o->product?->sku, 'qty' => $qty, 'standar' => $st['menit'], 'sumber' => $st['sumber']];
        }
        $merk   = $order->merk_bahan ?: [];
        $mj     = $order->merk_jumlah ?: [];
        $faktor = $this->faktorMerk($merk, $mj);

        return ['jatah' => $jatah * $faktor, 'rincian' => $rincian, 'kurang' => $kurang, 'merk' => $merk, 'mj' => $mj, 'faktor' => $faktor];
    }

    /**
     * Target waktu untuk kartu di layar Proses Produksi: step_id => [menit, rincian, pita].
     * Hanya divisi yang punya waktu standar disetujui & OP yang semua produknya bertarget.
     *
     * @param  Collection<int, ProductionOrderStep>  $steps  (productionOrder.outputs.product termuat)
     */
    public function targetKartu(Collection $steps): array
    {
        $peta = [];
        $hasil = [];
        foreach ($steps as $s) {
            $order = $s->productionOrder;
            if (!$order || $s->is_qc || in_array($order->type, WaktuStandarService::TIPE_DIKECUALIKAN, true)) continue;
            $standar = $peta[$s->department_id] ??= $this->petaStandar((int) $s->department_id, now(), false);
            if ($standar->isEmpty()) continue;

            $j = $this->hitungJatah($order, $standar);
            if ($j['kurang'] || !$j['rincian'] || $j['jatah'] <= 0) continue;
            $hasil[$s->id] = [
                'menit'   => round($j['jatah'], 1),
                'rincian' => collect($j['rincian'])->map(fn ($r) => rtrim(rtrim(number_format($r['qty'], 2, ',', ''), '0'), ',') . ' × ' . number_format($r['standar'], 2, ',', '.'))->implode(' + '),
                'faktor'  => $j['faktor'],
                'pita'    => $this->param()['pita'],
            ];
        }

        return $hasil;
    }

    /** Teks durasi untuk kartu: ≥ 60 mnt → "2 jam 31 mnt"; di bawahnya menit. Kembar fmtMnt() di layar Proses. */
    public static function teksMenit(float $menit): string
    {
        if ($menit >= 59.5) {
            $m = (int) round($menit);
            return intdiv($m, 60) . ' jam' . ($m % 60 ? ' ' . ($m % 60) . ' mnt' : '');
        }

        return ($menit >= 10 ? number_format($menit, 0, ',', '.') : number_format($menit, 1, ',', '.')) . ' mnt';
    }

    /** Jatah, timer, dan poin satu langkah. */
    public function nilaiLangkah(ProductionOrderStep $s, Collection $standar): array
    {
        $p     = $this->param();
        $menit = round($s->elapsed_working_seconds / 60, 1);
        ['jatah' => $jatah, 'rincian' => $rincian, 'kurang' => $kurang, 'merk' => $merk, 'mj' => $mj, 'faktor' => $faktor]
            = $this->hitungJatah($s->productionOrder, $standar);

        $dasar = [
            'step_id'   => $s->id,
            'op_id'     => $s->production_order_id,
            'op'        => $s->productionOrder->order_number,
            'selesai'   => $s->completed_at,
            'pelaksana' => $s->executors->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->all(),
            'menit'     => $menit,
            'rincian'   => $rincian,
            'kurang'    => $kurang,
            'simulasi'  => collect($rincian)->contains('sumber', 'simulasi'),
            'merk'      => $merk,
            'mj'        => $mj,
            'faktor'    => $faktor,
        ];

        $dasar['putusan'] = $s->penilaian_putusan;

        if (!$rincian && !$kurang) return $dasar + ['status' => 'kosong'];
        if ($kurang)               return $dasar + ['status' => 'tanpa_standar', 'jatah' => null, 'rasio' => null, 'poin' => null];

        $rasio = $menit > 0 && $jatah > 0 ? $menit / $jatah : null; // < 1 = lebih cepat dari jatah
        $jatah = round($jatah, 1);

        // Putusan owner (skill Pengawasan) menimpa penahanan otomatis.
        if ($s->penilaian_putusan === 'abaikan' || ($s->penilaian_putusan === 'wajar' && $rasio === null)) {
            return $dasar + ['status' => 'diabaikan', 'jatah' => $jatah, 'rasio' => $rasio, 'poin' => null];
        }
        if ($s->penilaian_putusan === 'curang') {
            return $dasar + ['status' => 'dinilai', 'jatah' => $jatah, 'rasio' => $rasio, 'poin' => 0];
        }
        if ($s->penilaian_putusan === 'wajar') {
            return $dasar + ['status' => 'dinilai', 'jatah' => $jatah, 'rasio' => $rasio, 'poin' => $this->poin($rasio)];
        }

        if ($rasio === null) return $dasar + ['status' => 'cek', 'jatah' => $jatah, 'rasio' => null, 'poin' => null, 'alasan' => 'timer kosong'];
        if ($rasio < 1 / $p['faktor_cek'] || $rasio > $p['faktor_cek']) {
            return $dasar + ['status' => 'cek', 'jatah' => $jatah, 'rasio' => $rasio, 'poin' => $this->poin($rasio),
                'alasan' => $rasio < 1 ? 'jauh lebih cepat dari jatah' : 'jauh lebih lambat dari jatah'];
        }

        return $dasar + ['status' => 'dinilai', 'jatah' => $jatah, 'rasio' => $rasio, 'poin' => $this->poin($rasio)];
    }

    /** Putusan owner atas langkah yang ditahan (kunci => label tombol). */
    public const PUTUSAN = [
        'wajar'   => 'Wajar — hitung sesuai timer',
        'abaikan' => 'Abaikan — tidak ikut penilaian',
        'curang'  => 'Tidak wajar — 0 poin',
    ];

    /**
     * Langkah yang ditahan otomatis (timer janggal) & belum diputus owner, selesai sejak $sejak.
     * Sumber kartu skill Pengawasan. Divisi = yang punya waktu standar disetujui.
     *
     * @return Collection<int, array> hasil nilaiLangkah() + 'department_id'
     */
    public function perluDiputus(Carbon $sejak): Collection
    {
        $depts = \App\Modules\Production\Models\ProductionStandardTime::query()->distinct()->pluck('department_id');
        $hasil = collect();
        foreach ($depts as $dept) {
            for ($b = $sejak->copy()->startOfMonth(); $b->lte(now()); $b->addMonth()) {
                foreach ($this->opBulan((int) $dept, $b)['op'] as $o) {
                    if ($o['status'] === 'cek' && !$o['putusan'] && $o['selesai'] && $o['selesai']->gte($sejak)) {
                        $hasil->push($o + ['department_id' => (int) $dept]);
                    }
                }
            }
        }

        return $hasil;
    }

    /** Nilai ulang satu langkah dengan standar bulan selesainya (untuk cek kartu masih relevan). */
    public function nilaiSatuLangkah(int $stepId): ?array
    {
        $s = ProductionOrderStep::with([
            'timeLogs', 'executors:id,name',
            'productionOrder:id,order_number,type,merk_bahan,merk_jumlah',
            'productionOrder.outputs' => fn ($q) => $q->where('output_type', 'main'),
            'productionOrder.outputs.product:id,sku,name',
        ])->find($stepId);
        if (!$s || $s->status !== 'completed' || !$s->completed_at) return null;

        return $this->nilaiLangkah($s, $this->petaStandar((int) $s->department_id, $s->completed_at->copy()->endOfMonth(), false));
    }

    /** Simpan putusan owner untuk satu langkah. */
    public function putuskan(int $stepId, string $putusan, ?int $userId): void
    {
        if (!array_key_exists($putusan, self::PUTUSAN)) {
            throw new \InvalidArgumentException("Putusan tidak dikenal: $putusan");
        }
        $this->memoBulan = [];
        \Illuminate\Support\Facades\DB::table('production_order_steps')->where('id', $stepId)->update([
            'penilaian_putusan'    => $putusan,
            'penilaian_putusan_at' => now(),
            'penilaian_putusan_by' => $userId,
        ]);
    }

    public function poin(float $rasio): int
    {
        $pita = $this->param()['pita'];
        if ($rasio <= 1 - $pita + 1e-9) return 2;
        if ($rasio <= 1 + $pita + 1e-9) return 1;

        return 0;
    }

    public function tingkat(?float $rata): ?array
    {
        if ($rata === null) return null;
        $tingkat = $this->param()['tingkat'];
        foreach ($tingkat as $t) {
            if ($rata >= $t['min'] - 1e-9) return $t;
        }

        return $tingkat[count($tingkat) - 1];
    }

    /** Ringkasan per pelaksana: jumlah OP per poin, rata-rata, efisiensi, tingkat bonus, per minggu. */
    private function ringkasPelaksana(int $departmentId, Collection $ops, Collection $tanpaStandar): Collection
    {
        $semua = DepartmentExecutor::where('department_id', $departmentId)->orderBy('name')
            ->get(['id', 'name', 'is_active', 'created_at']);

        return $semua->map(function ($e) use ($ops, $tanpaStandar) {
            $milik = $ops->filter(fn ($o) => collect($o['pelaksana'])->contains('id', $e->id))->values();
            $dinilai = $milik->where('status', 'dinilai');
            $n = $dinilai->count();
            $jatah = $dinilai->sum('jatah');
            $rata = !$n ? null : ($this->param()['bobot'] === 'jatah' && $jatah > 0
                ? $dinilai->sum(fn ($o) => $o['poin'] * $o['jatah']) / $jatah
                : $dinilai->avg('poin'));
            $timer = $dinilai->sum('menit');

            return [
                'id'            => $e->id,
                'nama'          => $e->name,
                'aktif'         => $e->is_active,
                'percobaan'     => $e->created_at && $e->created_at->gt(now()->subDays(WaktuStandarService::HARI_PERCOBAAN)),
                'n'             => $n,
                'poin2'         => $dinilai->where('poin', 2)->count(),
                'poin1'         => $dinilai->where('poin', 1)->count(),
                'poin0'         => $dinilai->where('poin', 0)->count(),
                'ditahan'       => $milik->where('status', 'cek')->count(),
                'tanpa_standar' => $tanpaStandar->filter(fn ($o) => collect($o['pelaksana'])->contains('id', $e->id))->count(),
                'rata'          => $rata,
                'efisiensi'     => $timer > 0 ? $jatah / $timer : null,
                'jatah'         => $jatah,
                'timer'         => $timer,
                'tingkat'       => $this->tingkat($rata),
                'op'            => $milik,
            ];
        })->filter(fn ($p) => $p['n'] || $p['ditahan'] || $p['tanpa_standar'])->values();
    }
}
