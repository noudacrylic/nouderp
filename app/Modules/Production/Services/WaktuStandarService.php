<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStandardTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Waktu standar kerja per SKU (menit/pcs) — dasar penilaian kinerja divisi produksi.
 *
 * Usulan = median menit/pcs riwayat langkah yang selesai, dari OP berisi satu produk utama.
 * OP custom IKUT (keputusan owner 14 Nov 2026: BC custom = kerja sama dengan BC biasa), kecuali
 * produk custom bebas CS1..CS6 yang isinya beda tiap pesanan. Catatan yang
 * jauh dari median (lebih cepat ½× atau lebih lambat 2×) dianggap tak wajar — biasanya timer
 * lupa dinyalakan / lupa dijeda — dan tidak ikut menentukan standar.
 *
 * Owner menyetujui atau mengoreksi usulan; keputusannya disimpan berlaku mulai tanggal
 * tertentu sehingga nilai bulan lalu tetap memakai standar saat itu.
 */
class WaktuStandarService
{
    /** Jumlah catatan wajar minimal agar median dianggap cukup kuat. */
    public const MIN_SAMPEL = 5;

    /** Batas wajar: catatan di luar median ÷ FAKTOR .. median × FAKTOR tidak dipakai. */
    public const FAKTOR_TAK_WAJAR = 2.0;

    /** Tipe OP yang tidak dinilai dengan waktu standar. Custom ikut — lihat skuCustomBebas(). */
    public const TIPE_DIKECUALIKAN = ['perbaikan', 'garansi', 'repair'];

    /** SKU custom bebas (CS1, CS2, …): isinya beda tiap pesanan, tak punya waktu standar. */
    public static function skuCustomBebas(?string $sku): bool
    {
        return $sku !== null && preg_match('/^CS\d+$/i', trim($sku)) === 1;
    }

    /** Pelaksana yang belum genap sekian hari di divisi dianggap masih percobaan. */
    public const HARI_PERCOBAAN = 60;

    /** Pelaksana bawaan untuk acuan: yang sudah lewat masa percobaan. */
    public function pelaksanaLama(int $departmentId): Collection
    {
        return DepartmentExecutor::where('department_id', $departmentId)
            ->where('created_at', '<=', now()->subDays(self::HARI_PERCOBAAN))
            ->orderBy('name')->pluck('id');
    }

    /**
     * Catatan kerja per langkah: menit/pcs untuk OP ready 1 produk.
     *
     * @param  int[]|null  $pelaksana  hanya langkah yang SEMUA pelaksananya ada di daftar ini
     */
    public function catatan(int $departmentId, Carbon $sejak, ?array $pelaksana = null, ?Carbon $sampai = null): Collection
    {
        $steps = ProductionOrderStep::query()
            ->with([
                'timeLogs',
                'executors:id,name',
                'productionOrder:id,order_number,type',
                'productionOrder.outputs' => fn ($q) => $q->where('output_type', 'main'),
                'productionOrder.outputs.product:id,sku,name',
            ])
            ->where('department_id', $departmentId)
            ->where('status', 'completed')
            ->where('is_qc', false)
            ->whereNull('sample_excluded_reason')
            ->where('completed_at', '>=', $sejak)
            ->when($sampai, fn ($q) => $q->where('completed_at', '<', $sampai))
            ->whereHas('productionOrder', fn ($q) => $q->whereNotIn('type', self::TIPE_DIKECUALIKAN))
            ->get();

        return $steps->map(function (ProductionOrderStep $s) use ($pelaksana) {
            $outs = $s->productionOrder->outputs;
            if ($outs->count() !== 1) return null;

            $ids = $s->executors->pluck('id')->all();
            if ($pelaksana !== null && (!$ids || array_diff($ids, $pelaksana))) return null;

            $o   = $outs->first();
            if (self::skuCustomBebas($o->product?->sku)) return null;
            $qty = (float) ($o->qty_produced > 0 ? $o->qty_produced : $o->qty_planned);
            $menit = $s->elapsed_working_seconds / 60;
            if ($qty <= 0 || $menit <= 0) return null;

            return [
                'dicoret'    => $s->standar_dicoret_at !== null, // disisihkan owner dari hitungan standar
                'step_id'    => $s->id,
                'op_id'      => $s->production_order_id,
                'op'         => $s->productionOrder->order_number,
                'product_id' => (int) $o->product_id,
                'sku'        => $o->product?->sku,
                'nama'       => $o->product?->name,
                'qty'        => $qty,
                'menit'      => round($menit, 1),
                'mpp'        => $menit / $qty,
                'pelaksana'  => $s->executors->pluck('name')->implode(', '),
                'selesai'    => $s->completed_at,
            ];
        })->filter()->values();
    }

    /**
     * Usulan standar per SKU dari catatan, digabung dengan standar yang berlaku sekarang.
     *
     * @return Collection<int, array> product_id => ringkasan
     */
    public function usulan(int $departmentId, Carbon $sejak, ?array $pelaksana = null): Collection
    {
        $berlaku = $this->berlakuSemua($departmentId, now());

        $hasil = $this->catatan($departmentId, $sejak, $pelaksana)
            ->groupBy('product_id')
            ->map(function (Collection $g) use ($berlaku) {
                // Sampel dicoret owner tidak ikut menentukan median kasar maupun hasil akhir.
                $dipakai = $g->where('dicoret', false);
                $kasar = $dipakai->isNotEmpty() ? $this->median($dipakai->pluck('mpp')->all()) : 0.0;
                $tandai = $g->map(fn ($r) => $r + ['wajar' => !$r['dicoret'] && $this->wajar($r['mpp'], $kasar)]);
                $wajar = $tandai->where('wajar', true)->pluck('mpp')->sort()->values()->all();
                $pid = $g->first()['product_id'];

                return [
                    'product_id' => $pid,
                    'sku'        => $g->first()['sku'],
                    'nama'       => $g->first()['nama'],
                    'n'          => $g->count(),
                    'n_wajar'    => count($wajar),
                    'n_coret'    => $g->where('dicoret', true)->count(),
                    'pcs'        => $g->sum('qty'),
                    // Rata-rata menit/pcs sampel wajar (cara owner): dasar usulan standar.
                    'rata'       => $wajar ? round(array_sum($wajar) / count($wajar), 2) : null,
                    'median'     => $wajar ? round($this->median($wajar), 2) : null,
                    'p25'        => $wajar ? round($this->persentil($wajar, 0.25), 2) : null,
                    'p75'        => $wajar ? round($this->persentil($wajar, 0.75), 2) : null,
                    'cukup'      => count($wajar) >= self::MIN_SAMPEL,
                    'berlaku'    => $berlaku->get($pid),
                    'catatan'    => $tandai->sortByDesc('selesai')->values(),
                ];
            });

        // SKU yang sudah punya standar tapi tak muncul di riwayat periode ini tetap ditampilkan.
        foreach ($berlaku as $pid => $st) {
            if (!$hasil->has($pid)) {
                $hasil[$pid] = [
                    'product_id' => $pid, 'sku' => $st->product?->sku, 'nama' => $st->product?->name,
                    'n' => 0, 'n_wajar' => 0, 'n_coret' => 0, 'pcs' => 0, 'rata' => null, 'median' => null, 'p25' => null, 'p75' => null,
                    'cukup' => false, 'berlaku' => $st, 'catatan' => collect(),
                ];
            }
        }

        return $hasil->sortBy([['cukup', 'desc'], ['n_wajar', 'desc'], ['sku', 'asc']]);
    }

    /** Standar yang berlaku pada tanggal tertentu, per produk. */
    public function berlakuSemua(int $departmentId, Carbon $tanggal): Collection
    {
        return ProductionStandardTime::with('product:id,sku,name')
            ->where('department_id', $departmentId)
            ->where('effective_from', '<=', $tanggal->toDateString())
            ->orderBy('effective_from')->orderBy('id')
            ->get()
            ->keyBy('product_id'); // yang terakhir (paling baru) menang
    }

    public function berlaku(int $departmentId, int $productId, Carbon $tanggal): ?ProductionStandardTime
    {
        return ProductionStandardTime::where('department_id', $departmentId)->where('product_id', $productId)
            ->where('effective_from', '<=', $tanggal->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /**
     * Simpan keputusan owner. Nilai yang sama dengan standar berlaku dilewati; standar di
     * tanggal berlaku yang sama ditimpa (koreksi), tanggal lain jadi baris baru (riwayat).
     *
     * @param  array<int, array{menit: float, sumber?: string, sampel?: ?int, usulan?: ?float}>  $isi
     * @return int jumlah SKU yang berubah
     */
    public function simpan(int $departmentId, array $isi, Carbon $berlakuMulai, ?int $userId, ?string $catatan = null): int
    {
        return DB::transaction(function () use ($departmentId, $isi, $berlakuMulai, $userId, $catatan) {
            $berubah = 0;
            foreach ($isi as $productId => $d) {
                $menit = round((float) $d['menit'], 3);
                if ($menit <= 0) continue;

                $kini = $this->berlaku($departmentId, (int) $productId, $berlakuMulai);
                if ($kini && abs($kini->minutes_per_unit - $menit) < 0.0005) continue;

                ProductionStandardTime::updateOrCreate(
                    ['department_id' => $departmentId, 'product_id' => (int) $productId, 'effective_from' => $berlakuMulai->toDateString()],
                    [
                        'minutes_per_unit'  => $menit,
                        'source'            => in_array($d['sumber'] ?? '', ['median', 'rata'], true) ? $d['sumber'] : 'manual',
                        'sample_count'      => $d['sampel'] ?? null,
                        'suggested_minutes' => $d['usulan'] ?? null,
                        'notes'             => $catatan,
                        'approved_by'       => $userId,
                    ]
                );
                $berubah++;
            }

            return $berubah;
        });
    }

    /** Coret / pulihkan satu sampel untuk hitungan standar (tidak memengaruhi penilaian & HPP). */
    public function coretSampel(int $stepId, bool $coret, ?int $userId): void
    {
        DB::table('production_order_steps')->where('id', $stepId)->update([
            'standar_dicoret_at' => $coret ? now() : null,
            'standar_dicoret_by' => $coret ? $userId : null,
        ]);
    }

    public function wajar(float $mpp, float $median): bool
    {
        return $median > 0 && $mpp >= $median / self::FAKTOR_TAK_WAJAR && $mpp <= $median * self::FAKTOR_TAK_WAJAR;
    }

    private function median(array $a): float
    {
        return $this->persentil($a, 0.5);
    }

    /** Persentil dengan interpolasi linear. */
    private function persentil(array $a, float $p): float
    {
        sort($a);
        $n = count($a);
        if (!$n) return 0.0;
        $i = ($n - 1) * $p;
        $lo = (int) floor($i);
        $hi = (int) ceil($i);

        return $a[$lo] + ($a[$hi] - $a[$lo]) * ($i - $lo);
    }
}
