<?php

namespace App\Modules\Analysis\Services;

use App\Modules\Analysis\Models\ProductionTimeAssumption;
use App\Modules\Production\Models\ProductionOrderStep;
use Illuminate\Support\Facades\DB;

/**
 * Lapisan analisa di atas Waktu Produksi.
 *
 * Sengaja dipisah dari ProductionTimeAnalysisService, yang tugasnya cuma satu: mengukur waktu
 * dari timer, apa adanya. Semua yang bersifat pengandaian dan turunan hidup di sini, supaya
 * "apa yang terukur" tidak pernah tercampur dengan "apa yang kita andaikan".
 *
 * Tiga hal yang ditambahkan:
 *
 *  1. **Asumsi waktu per unit** — PEMBILANG model HPP. Kuota mengandaikan penyebutnya (jam per
 *     slot); di sini pembilangnya. Satu tuas per halaman.
 *  2. **Kapasitas rata-rata per hari** — detik per unit diubah jadi "berapa unit sehari", dengan
 *     divisi terlambat sebagai penentu. Kapasitas divisinya diambil dari ProductionQuotaService
 *     supaya kalau asumsi jam di sana berubah, angka di sini ikut berubah pada saat yang sama.
 *  3. **Rincian per mesin** — waktu per unit dipecah menurut eksekutor yang mengerjakannya.
 *     Tanpa ini, HPP sebuah produk diam-diam bergantung pada mesin mana yang kebetulan kosong,
 *     dan keunggulan mesin yang lebih cepat tenggelam di dalam rata-rata.
 */
class ProductionTimeInsightService
{
    public function __construct(
        protected ProductionTimeAnalysisService $timeService,
        protected ProductionQuotaService $quotaService,
    ) {
    }

    /**
     * Tempelkan asumsi & kapasitas ke baris-baris hasil pengukuran.
     *
     * @param  iterable<int,array>  $rows  keluaran perProduct()/forProduct()
     * @return array<int,array>
     */
    public function enrich(iterable $rows, array $filters = []): array
    {
        $capacity    = $this->quotaService->capacityPerDay($filters);
        $assumptions = $this->assumptionMap();

        $out = [];
        foreach ($rows as $key => $row) {
            $out[$key] = $this->enrichRow($row, $capacity, $assumptions);
        }

        return $out;
    }

    /** Satu baris saja — dipakai halaman detail produk. */
    public function enrichOne(array $row, array $filters = []): array
    {
        return $this->enrichRow($row, $this->quotaService->capacityPerDay($filters), $this->assumptionMap());
    }

    public function capacityPerDay(array $filters = []): array
    {
        return $this->quotaService->capacityPerDay($filters);
    }

    // ── Asumsi + kapasitas ────────────────────────────────────────────────────────

    protected function enrichRow(array $row, array $capacity, array $assumptions): array
    {
        $productId  = (int) ($row['product']['id'] ?? 0);
        $bottleneck = null;
        $totalSec   = 0.0;
        $adaAsumsi  = false;

        // Divisi yang belum pernah terukur tetap dibuatkan sel kalau ada asumsinya — justru
        // produk seperti itu yang paling butuh ditambal.
        $deptIds = array_unique(array_merge(
            array_keys($row['per_division'] ?? []),
            array_keys($assumptions[$productId] ?? [])
        ));

        $perDivision = $row['per_division'] ?? [];

        foreach ($deptIds as $deptId) {
            $cell   = $perDivision[$deptId] ?? [
                'department'    => $this->departmentMeta((int) $deptId),
                'sec_per_cycle' => null,
                'sec_per_unit'  => null,
                'n'             => 0,
            ];

            $a     = $assumptions[$productId][$deptId] ?? null;
            $pakai = (bool) $a?->use_assumption && $a->assumed_seconds_per_unit !== null;
            $adaAsumsi = $adaAsumsi || $pakai;

            $efektif = $pakai ? (float) $a->assumed_seconds_per_unit : $cell['sec_per_unit'];

            $cell['assumed']        = $a?->assumed_seconds_per_unit;
            $cell['assumption_note'] = $a?->notes;
            $cell['use_assumption'] = $pakai;
            $cell['sec_per_unit_effective'] = $efektif;

            $cap = $capacity[$deptId]['seconds_per_day'] ?? 0;
            $cell['capacity_per_day'] = ($efektif > 0 && $cap > 0) ? $cap / $efektif : null;
            $cell['slot_count']       = $capacity[$deptId]['slot_count'] ?? 0;
            $cell['is_bottleneck']    = false;

            if ($efektif > 0) {
                $totalSec += $efektif;
            }

            if ($cell['capacity_per_day'] !== null
                && ($bottleneck === null || $cell['capacity_per_day'] < $perDivision[$bottleneck]['capacity_per_day'])) {
                $bottleneck = $deptId;
            }

            $perDivision[$deptId] = $cell;
        }

        if ($bottleneck !== null) {
            $perDivision[$bottleneck]['is_bottleneck'] = true;
        }

        $hariKerja = $bottleneck !== null ? ($capacity[$bottleneck]['working_days'] ?? 0) : 0;
        $perDay    = $bottleneck !== null ? $perDivision[$bottleneck]['capacity_per_day'] : null;

        $row['per_division']           = $perDivision;
        $row['sec_per_unit_effective'] = $totalSec > 0 ? $totalSec : null;
        $row['has_assumption']         = $adaAsumsi;
        $row['bottleneck_id']          = $bottleneck;
        $row['bottleneck_name']        = $bottleneck !== null ? ($perDivision[$bottleneck]['department']['name'] ?? null) : null;
        $row['capacity_per_day']       = $perDay;
        $row['capacity_per_month']     = $perDay !== null ? $perDay * $hariKerja : null;

        return $row;
    }

    /** @return array<int,array<int,ProductionTimeAssumption>> [product_id][department_id] */
    protected function assumptionMap(): array
    {
        $out = [];
        foreach (ProductionTimeAssumption::get() as $a) {
            $out[$a->product_id][$a->department_id] = $a;
        }

        return $out;
    }

    protected function departmentMeta(int $deptId): array
    {
        static $cache = null;
        $cache ??= DB::table('production_departments')->get(['id', 'code', 'name', 'type'])
            ->keyBy('id')->map(fn ($d) => (array) $d)->all();

        return $cache[$deptId] ?? ['id' => $deptId, 'code' => null, 'name' => 'Divisi #' . $deptId, 'type' => null];
    }

    // ── Rincian per mesin ─────────────────────────────────────────────────────────

    /**
     * Waktu per unit sebuah produk, dipecah menurut eksekutor yang mengerjakannya.
     *
     * Hanya memakai OP yang persis sama dengan yang dipakai menghitung rata-rata — kalau tidak,
     * rinciannya akan bercerita tentang sampel yang justru sudah dikecualikan operator.
     *
     * Langkah yang dikerjakan beberapa eksekutor sekaligus dihitung penuh di masing-masing;
     * ini rincian diagnostik, bukan pembagi kapasitas, jadi tidak boleh dibagi rata (waktu
     * mesinnya memang selama itu, bukan separuhnya).
     *
     * @return array<int,array{department:string, executor:string, samples:int, sec_per_unit:float}>
     */
    public function perExecutor(int $productId, array $filters = []): array
    {
        $orderIds = $this->timeService->perProductSampleOrderIds($filters)[$productId] ?? [];
        if (empty($orderIds)) {
            return [];
        }

        $qty = DB::table('production_orders as o')
            ->join('production_order_outputs as po', function ($j) use ($productId) {
                $j->on('po.production_order_id', '=', 'o.id')
                  ->where('po.output_type', '=', 'main')->where('po.product_id', '=', $productId);
            })
            ->whereIn('o.id', $orderIds)
            ->selectRaw('o.id, GREATEST(po.qty_produced, po.qty_planned) as qty')
            ->pluck('qty', 'id');

        $steps = ProductionOrderStep::with('timeLogs')
            ->whereIn('production_order_id', $orderIds)
            ->where('status', 'completed')
            ->get();

        $executors = DB::table('production_department_executors as e')
            ->join('production_departments as d', 'd.id', '=', 'e.department_id')
            ->select('e.id', 'e.name', 'd.name as dept')->get()->keyBy('id');

        $pivot = DB::table('production_order_step_executors')
            ->whereIn('step_id', $steps->pluck('id'))->get()->groupBy('step_id');

        $agg = [];
        foreach ($steps as $step) {
            $unit = (float) ($qty[$step->production_order_id] ?? 0);
            if ($unit <= 0) {
                continue;
            }

            $perUnit = $step->elapsed_working_seconds / $unit;

            foreach (($pivot[$step->id] ?? collect()) as $p) {
                $ex = $executors[$p->executor_id] ?? null;
                if (!$ex) {
                    continue;
                }
                $agg[$p->executor_id]['department'] = $ex->dept;
                $agg[$p->executor_id]['executor']   = $ex->name;
                $agg[$p->executor_id]['values'][]   = $perUnit;
            }
        }

        $out = [];
        foreach ($agg as $id => $row) {
            $v = $row['values'];
            sort($v);

            $out[] = [
                'executor_id'  => $id,
                'department'   => $row['department'],
                'executor'     => $row['executor'],
                'samples'      => count($v),
                'sec_per_unit' => array_sum($v) / count($v),
                'median'       => $v[intdiv(count($v), 2)],
                'min'          => $v[0],
                'max'          => $v[count($v) - 1],
            ];
        }

        usort($out, fn ($a, $b) => [$a['department'], $a['executor']] <=> [$b['department'], $b['executor']]);

        return $out;
    }
}
