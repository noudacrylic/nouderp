<?php

namespace App\Modules\Analysis\Services;

use App\Modules\Analysis\Models\ProductionTimeSampleExclusion;
use App\Modules\Analysis\Support\ProductionTimeMath as Math;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Support\Collection;
use App\Modules\Analysis\Support\AnalysisCache;

/**
 * Mesin analisa rata-rata waktu produksi per produk per divisi.
 *
 * Kontrak yang dijaga (dipakai ulang Tahap 2 — kapasitas & HPP):
 *  • semua matematika lewat ProductionTimeMath / service ini, blade hanya memformat;
 *  • selalu mengembalikan DETIK MENTAH (float), tidak pernah string terformat;
 *  • tidak menyentuh request()/session()/auth() — filter dikirim eksplisit sebagai array,
 *    supaya bisa dipanggil dari command/job.
 *
 * Hasil TIDAK di-snapshot: rata-rata dihitung ulang tiap kali, sehingga OP baru
 * otomatis ikut. Yang tersimpan hanya daftar OP yang dikecualikan operator.
 */
class ProductionTimeAnalysisService
{
    public function __construct(protected AnalysisCache $cache)
    {
    }

    /** Status OP yang seluruh langkahnya sudah tuntas → data timer lengkap. */
    public const ELIGIBLE_STATUSES = ['finalized', 'completed', 'pending'];

    /** Tipe OP yang dianalisa secara default (perbaikan/garansi beda karakter kerjanya). */
    public const DEFAULT_TYPES = ['ready_stock', 'custom'];

    /**
     * Divisi yang tercatat 0 detik dianggap "tidak tersampel", bukan nilai 0.
     * Alasan: revertToPreviousStep() menghapus time log, jadi 0 lebih sering berarti
     * "datanya hilang" daripada "benar-benar tanpa waktu" — kalau dihitung sebagai 0
     * rata-rata divisi itu tertarik ke bawah tanpa ketahuan.
     */
    public const TREAT_ZERO_DIVISION_AS_MISSING = true;

    /** Kunci bucket untuk langkah tanpa divisi (department_id nullable). */
    public const NO_DEPT_KEY = 0;

    // ==========================================================
    // API PUBLIK
    // ==========================================================

    /**
     * Ringkasan per produk untuk halaman index (tanpa daftar sampel).
     *
     * @return Collection<int,array> dikunci product_id
     */
    public function perProduct(array $filters = []): Collection
    {
        return $this->buildAll($filters)->map(function (array $row) {
            unset($row['samples'], $row['ineligible_samples']);
            return $row;
        });
    }

    /** Detail satu produk, lengkap dengan daftar sampelnya. */
    public function forProduct(int $productId, array $filters = []): ?array
    {
        $filters['product_id'] = $productId;

        return $this->buildAll($filters)->get($productId);
    }

    /**
     * SEAM Tahap 2 — [product_id => [department_id => detik per unit]].
     * Kapasitas: detik_kerja_tersedia[divisi] / detik_per_unit[produk][divisi].
     * HPP: Σ detik_per_unit[divisi] × tarif_per_detik[divisi].
     */
    public function secPerUnitMap(array $filters = []): array
    {
        return $this->perProduct($filters)
            ->map(fn ($row) => collect($row['per_division'])
                ->map(fn ($d) => $d['sec_per_unit'])
                ->filter(fn ($v) => $v !== null)
                ->all())
            ->filter(fn ($m) => !empty($m))
            ->all();
    }

    /**
     * SEAM Tahap 2 — [product_id => int[] order_id sampel yang DIPAKAI].
     * Dipakai ProductHppService supaya biaya bahan mengambil OP yang persis sama
     * dengan perhitungan waktu, termasuk pengecualian yang disimpan operator.
     */
    public function perProductSampleOrderIds(array $filters = []): array
    {
        return $this->buildAll($filters)
            ->map(fn ($row) => collect($row['samples'])
                ->filter(fn ($s) => !$s['excluded'])
                ->pluck('order_id')
                ->map(fn ($id) => (int) $id)
                ->all())
            ->filter(fn ($ids) => !empty($ids))
            ->all();
    }

    /** Divisi produksi aktif — dipakai untuk dropdown filter. */
    public function departmentsForFilter(): Collection
    {
        return Department::where('is_active', 1)->orderBy('name')->get(['id', 'code', 'name', 'type']);
    }

    /** Jumlah OP layak yang tersembunyi karena aturan OP gabungan. */
    public function mergedSampleCount(array $filters = []): int
    {
        $filters['include_merged'] = true;

        return $this->baseQuery($filters)
            ->where(fn ($q) => $q->whereNotNull('merged_into_id')->orWhereHas('mergedChildren'))
            ->count();
    }

    /**
     * Simpan pilihan sampel.
     *
     * Hanya OP yang ID-nya dirender di form yang boleh disentuh — kalau tidak,
     * menyimpan saat filter aktif akan ikut menghapus pengecualian OP yang
     * sedang tidak tampil.
     *
     * @param  int[]  $renderedIds  semua OP yang muncul di form
     * @param  int[]  $keepIds      OP yang tercentang (= dipakai)
     * @return array{excluded: int, restored: int}
     */
    public function saveExclusions(array $renderedIds, array $keepIds, ?string $reason, ?int $userId): array
    {
        $rendered = array_map('intval', $renderedIds);
        $keep     = array_intersect(array_map('intval', $keepIds), $rendered);
        $drop     = array_diff($rendered, $keep);

        $restored = empty($keep) ? 0
            : ProductionTimeSampleExclusion::whereIn('production_order_id', $keep)->delete();

        foreach ($drop as $orderId) {
            ProductionTimeSampleExclusion::updateOrCreate(
                ['production_order_id' => $orderId],
                ['reason' => $reason, 'excluded_by' => $userId],
            );
        }

        return ['excluded' => count($drop), 'restored' => (int) $restored];
    }

    // ==========================================================
    // INTERNAL
    // ==========================================================

    /** @return Collection<int,array> dikunci product_id */
    protected function buildAll(array $filters): Collection
    {
        return $this->cache->remember('waktu.buildAll', $filters, fn () => $this->hitungSemua($filters));
    }

    protected function hitungSemua(array $filters): Collection
    {
        $orders     = $this->eligibleOrders($filters);
        $exclusions = ProductionTimeSampleExclusion::pluck('reason', 'production_order_id');

        // Kelompokkan sampel per produk utama
        $byProduct = [];
        foreach ($orders as $order) {
            $sample = $this->buildSample($order, $exclusions, $filters);
            if ($sample === null) {
                continue;
            }
            $byProduct[$sample['product_id']][] = $sample;
        }

        $products = $orders->flatMap(fn ($o) => $o->outputs)
            ->filter(fn ($out) => $out->product)
            ->keyBy('product_id')
            ->map(fn ($out) => $out->product);

        $rows = collect($byProduct)->map(
            fn (array $samples, $productId) => $this->aggregate($products[$productId] ?? null, $samples)
        );

        return $rows->sortBy(fn ($r) => $r['product']['name'] ?? '')->keyBy(fn ($r) => $r['product']['id']);
    }

    protected function baseQuery(array $filters)
    {
        $types = $filters['types'] ?? self::DEFAULT_TYPES;

        return ProductionOrder::query()
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->when(!empty($types), fn ($q) => $q->whereIn('type', $types))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('production_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('production_date', '<=', $filters['date_to']))
            ->when(!empty($filters['product_id']), fn ($q) => $q->whereHas(
                'outputs',
                fn ($o) => $o->where('output_type', 'main')->where('product_id', $filters['product_id'])
            ))
            ->whereHas('steps.timeLogs');
    }

    /** @return Collection<int,ProductionOrder> */
    protected function eligibleOrders(array $filters): Collection
    {
        $query = $this->baseQuery($filters);

        // OP gabungan dibuang di level query, bukan lewat tabel pengecualian:
        // pengecualian bersifat opt-out, jadi OP gabungan baru akan menyelinap masuk lagi.
        // Alasan teknis: mergeOrders() menambah planned_cycles induk dengan siklus anak
        // (ProductionOrderService.php:1534) padahal langkah induk yang selesai sebelum
        // merge hanya memuat kerja untuk siklusnya sendiri → per-siklus induk terlalu
        // kecil; dan langkah anak berhenti di titik merge → total waktunya tidak lengkap.
        if (empty($filters['include_merged'])) {
            $query->whereNull('merged_into_id')->whereDoesntHave('mergedChildren');
        }

        return $query->with([
                'steps'                => fn ($q) => $q->orderBy('step_number'),
                'steps.timeLogs',        // WAJIB — elapsed_working_seconds accessor PHP, bukan kolom
                'steps.department:id,code,name,type',
                // Siapa yang mengerjakan tiap langkah. Sumbernya pivot, BUKAN kolom
                // `executor_id` yang lama: semua langkah yang punya kolom itu juga ada
                // di pivot, sedangkan pivot menampung langkah bertangan banyak.
                'steps.executors:id,name',
                'outputs'              => fn ($q) => $q->where('output_type', 'main'),
                'outputs.product:id,sku,name,base_unit',
                'bom:id,bom_number,name',
                'bom.outputs'          => fn ($q) => $q->where('output_type', 'main'),
                'mergedChildren:id,merged_into_id',
            ])
            ->orderBy('production_date')
            ->orderBy('id')
            ->get();
    }

    /** Bangun satu baris sampel dari sebuah OP. Null bila OP tak punya output utama. */
    protected function buildSample(ProductionOrder $order, Collection $exclusions, array $filters): ?array
    {
        $mains = $order->outputs; // sudah difilter output_type=main
        if ($mains->isEmpty()) {
            return null;
        }

        $flags    = [];
        $eligible = true;

        // OP perbaikan/garansi bisa punya banyak baris "main" (tiap SKU independen) —
        // waktunya tidak bisa dibagi ke SKU mana, jadi dilewati.
        if ($mains->count() > 1) {
            $flags[]  = 'multi_main';
            $eligible = false;
        }

        $productId = (int) $mains->first()->product_id;
        $cycles    = (float) $order->planned_cycles;

        if ($cycles <= 0) {
            $flags[]  = 'no_cycles';
            $eligible = false;
        }

        // Hanya langkah 'completed': accessor menambahkan now() untuk langkah in_progress.
        $doneSteps = $order->steps->where('status', 'completed');
        if ($doneSteps->count() !== $order->steps->count()) {
            $flags[] = 'unfinished_steps';
        }

        $secByDept = $doneSteps
            ->groupBy(fn ($s) => (int) ($s->department_id ?: self::NO_DEPT_KEY))
            ->map(fn ($steps) => (int) $steps->sum('elapsed_working_seconds'))
            ->all();

        // Siapa yang mengerjakan, per divisi. Satu langkah bisa dikerjakan beberapa
        // eksekutor sekaligus, dan satu divisi bisa punya beberapa langkah — jadi
        // dikumpulkan sebagai himpunan nama, bukan satu nama per divisi.
        $execByDept = [];
        foreach ($doneSteps as $step) {
            $deptKey = (int) ($step->department_id ?: self::NO_DEPT_KEY);
            foreach ($step->executors as $ex) {
                $execByDept[$deptKey][(int) $ex->id] = $ex->name;
            }
        }
        $execByDept = array_map(fn ($names) => array_values($names), $execByDept);

        if (!empty($filters['department_id'])) {
            $deptId     = (int) $filters['department_id'];
            $secByDept  = array_intersect_key($secByDept, [$deptId => true]);
            $execByDept = array_intersect_key($execByDept, [$deptId => true]);
        }

        $totalSec = array_sum($secByDept);
        if ($totalSec <= 0) {
            $flags[]  = 'zero_time';
            $eligible = false;
        }

        $secForAvg = self::TREAT_ZERO_DIVISION_AS_MISSING
            ? array_filter($secByDept, fn ($v) => $v > 0)
            : $secByDept;
        if (count($secForAvg) !== count($secByDept)) {
            $flags[] = 'zero_division';
        }

        $isMergedChild  = $order->merged_into_id !== null;
        $isMergedParent = $order->mergedChildren->isNotEmpty();
        if ($isMergedChild)  { $flags[] = 'merged_child';  $eligible = false; }
        if ($isMergedParent) { $flags[] = 'merged_parent'; $eligible = false; }

        // qty_per_cycle dari BOM yang terhubung ke OP ini (baris main untuk produk ybs).
        // Bom::mainOutput() adalah hasMany, jadi diambil lewat bom.outputs.
        $bomRow      = $order->bom?->outputs->firstWhere('product_id', $productId);
        $qtyPerCycle = $bomRow ? (float) $bomRow->qty_per_cycle : null;
        if (!$qtyPerCycle || $qtyPerCycle <= 0) {
            $qtyPerCycle = null;
            $flags[]     = 'no_bom';
        }

        $secPerCycle = $cycles > 0 ? Math::secPerCycle($secForAvg, $cycles) : [];

        $excluded = $exclusions->has($order->id);

        return [
            'product_id'          => $productId,
            'order_id'            => $order->id,
            'order_number'        => $order->order_number,
            'production_date'     => optional($order->production_date)->format('Y-m-d'),
            'type'                => $order->type,
            'type_label'          => $order->type_label,
            'status'              => $order->status,
            'status_label'        => $order->status_label,
            'planned_cycles'      => $cycles,
            'bom_id'              => $order->bom_id ? (int) $order->bom_id : null,
            'bom_number'          => $order->bom?->bom_number,
            'bom_name'            => $order->bom?->name,
            'qty_per_cycle'       => $qtyPerCycle,
            'sec'                 => $secByDept,
            'executors'           => $execByDept,
            'sec_per_cycle'       => $secPerCycle,
            'total_sec'           => (float) $totalSec,
            'total_sec_per_cycle' => $cycles > 0 ? array_sum($secPerCycle) : null,
            'excluded'            => $excluded,
            'exclusion_reason'    => $excluded ? $exclusions[$order->id] : null,
            'flags'               => $flags,
            'is_eligible'         => $eligible,
            'is_outlier'          => false, // diisi di aggregate()
        ];
    }

    /** Agregasi sampel-sampel satu produk menjadi baris hasil. */
    protected function aggregate($product, array $samples): array
    {
        $eligible   = array_values(array_filter($samples, fn ($s) => $s['is_eligible']));
        $ineligible = array_values(array_filter($samples, fn ($s) => !$s['is_eligible']));
        $included   = array_values(array_filter($eligible, fn ($s) => !$s['excluded']));

        // Penanda menyimpang — petunjuk visual saja, tidak membuang sampel otomatis.
        $totals = [];
        foreach ($included as $s) {
            $totals[$s['order_id']] = (float) $s['total_sec_per_cycle'];
        }
        $outliers = Math::outlierFlags($totals);
        foreach ($samples as $i => $s) {
            $samples[$i]['is_outlier'] = $outliers[$s['order_id']] ?? false;
        }

        $qtyRes      = Math::resolveQtyPerCycle($included);
        $qtyPerCycle = $qtyRes['qty_per_cycle'];
        $avg         = Math::averagePerDivision($included);

        $perDivision = [];
        foreach ($avg as $deptId => $row) {
            $perDivision[$deptId] = [
                'department'    => $this->departmentMeta((int) $deptId),
                'sec_per_cycle' => $row['avg'],
                'sec_per_unit'  => Math::perUnit($row['avg'], $qtyPerCycle),
                'n'             => $row['n'],
            ];
        }

        $totalPerCycle = array_sum(array_column($perDivision, 'sec_per_cycle'));

        return [
            'product' => $product ? [
                'id'        => (int) $product->id,
                'sku'       => $product->sku,
                'name'      => $product->name,
                'base_unit' => $product->base_unit,
            ] : ['id' => 0, 'sku' => '—', 'name' => '(produk terhapus)', 'base_unit' => null],

            'per_division' => $perDivision,
            'total'        => [
                'sec_per_cycle' => $totalPerCycle,
                'sec_per_unit'  => Math::perUnit($totalPerCycle, $qtyPerCycle),
            ],

            'qty_per_cycle'          => $qtyPerCycle,
            'qty_per_cycle_source'   => $qtyRes['source'],
            'qty_per_cycle_conflict' => $qtyRes['conflicts'],

            'samples'            => $this->sortSamples(array_filter($samples, fn ($s) => $s['is_eligible'])),
            'ineligible_samples' => $this->sortSamples($ineligible),

            'included_count'   => count($included),
            'excluded_count'   => count($eligible) - count($included),
            'ineligible_count' => count($ineligible),
            'median_sec_per_cycle' => Math::median($totals),
        ];
    }

    /** Cache divisi: satu query untuk seluruh request, hindari N+1 di loop agregasi. */
    protected ?Collection $deptCache = null;

    protected function departmentMeta(int $deptId): array
    {
        if ($deptId === self::NO_DEPT_KEY) {
            return ['id' => 0, 'code' => null, 'name' => 'Tanpa Divisi', 'type' => null];
        }

        $this->deptCache ??= Department::get(['id', 'code', 'name', 'type'])->keyBy('id');
        $dept = $this->deptCache->get($deptId);

        return $dept
            ? ['id' => $dept->id, 'code' => $dept->code, 'name' => $dept->name, 'type' => $dept->type]
            : ['id' => $deptId, 'code' => null, 'name' => 'Divisi #' . $deptId, 'type' => null];
    }

    protected function sortSamples(array $samples): array
    {
        usort($samples, fn ($a, $b) => [$b['production_date'], $b['order_id']] <=> [$a['production_date'], $a['order_id']]);

        return array_values($samples);
    }
}
