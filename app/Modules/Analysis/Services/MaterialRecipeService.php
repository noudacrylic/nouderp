<?php

namespace App\Modules\Analysis\Services;

use App\Modules\Analysis\Models\MaterialPriceAssumption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Modules\Analysis\Support\AnalysisCache;

/**
 * Menguraikan variable cost menjadi bahan-bahannya, supaya pertanyaan "kalau akrilik naik
 * jadi Rp360.000, saya rugi tidak" bisa dijawab dengan angka.
 *
 * ── KENAPA HARUS BERJENJANG ───────────────────────────────────────────────
 *
 * Bahan LANGSUNG sebuah produk sering bukan bahan beli, melainkan bahan setengah jadi
 * buatan sendiri:
 *
 *     Akrilik lembaran 2 mm (beli)  →  Bahan Nama Meja (OP)  →  Nama Meja (OP, dijual)
 *
 * Kalau penelusuran berhenti di satu tingkat, menaikkan harga akrilik akan tampak
 * BERDAMPAK NOL pada produk jadinya — padahal seluruh isinya akrilik. Lebih dari separuh
 * bahan yang dipakai di sini berupa barang setengah jadi, jadi penelusuran berjenjang bukan
 * kemewahan melainkan syarat supaya angkanya tidak menyesatkan.
 *
 * ── TAKARANNYA DARI MANA ──────────────────────────────────────────────────
 *
 * Dari OP yang benar-benar dikerjakan (`production_order_materials` ÷ hasil di kartu stok),
 * bukan dari BOM. Alasannya sama dengan alasan halaman HPP memakai waktu terukur: yang
 * dipakai menetapkan harga harus kenyataan, termasuk susut dan bahan tambahan. Untuk produk
 * yang punya sampel, OP-nya persis sampel yang sama dengan yang dipakai waktu & biaya;
 * untuk bahan setengah jadi yang tidak masuk daftar sampel, dipakai beberapa OP terakhirnya.
 *
 * ── DUA ANGKA YANG DIHASILKAN ─────────────────────────────────────────────
 *
 *   harga hari ini  = takaran × harga beli TERAKHIR tiap bahan
 *   asumsi          = takaran × harga yang diketik operator (bahan tanpa asumsi tetap
 *                     memakai harga beli terakhirnya)
 *
 * Keduanya dihitung ulang dari resep, bukan menambal variable cost tercatat, karena
 * pertanyaannya tentang masa depan: seluruh bahan harus dinilai dengan harga yang akan
 * berlaku, bukan dengan lapisan stok lama yang kebetulan masih murah.
 *
 * CAKUPAN: angka analisa. Tidak pernah menyentuh persediaan maupun jurnal.
 */
class MaterialRecipeService
{
    /** Sedalam apa penelusuran bahan-dari-bahan boleh turun sebelum dianggap melingkar. */
    private const MAX_DEPTH = 6;

    /** OP terakhir yang dipakai menakar bahan setengah jadi (yang tidak punya sampel). */
    private const RECENT_OPS = 3;

    private array $cache = [];

    public function __construct(
        protected ProductionTimeAnalysisService $timeService,
        protected AnalysisCache $simpanan,
    ) {
    }

    /**
     * Peta resep + harga, siap dipakai menghitung.
     *
     * @return array{
     *   recipes: array<int,array<int,float>>,   takaran bahan per unit, dikunci product_id
     *   sources: array<int,string>,             'sampel' | 'op-terakhir'
     *   prices:  array<int,array>,              harga beli terakhir tiap bahan
     *   leaves:  array<int,int>                 bahan beli → dipakai berapa produk jadi
     * }
     */
    public function build(array $filters = []): array
    {
        $key = md5(serialize($filters));

        return $this->cache[$key] ??= $this->compute($filters);
    }

    /**
     * Biaya bahan per unit tiap produk.
     *
     * @param bool $withAssumption pakai harga asumsi (true) atau harga beli terakhir (false)
     * @return array<int,float|null>
     */
    public function costs(array $filters = [], bool $withAssumption = false): array
    {
        return $this->simpanan->remember('bahan.costs', array_merge($filters, ['asumsi' => $withAssumption]),
            fn () => $this->hitungCosts($filters, $withAssumption));
    }

    protected function hitungCosts(array $filters, bool $withAssumption): array
    {
        $data        = $this->build($filters);
        $assumptions = $withAssumption ? MaterialPriceAssumption::map() : [];

        $memo = [];
        $out  = [];

        foreach (array_keys($data['recipes']) as $pid) {
            $out[$pid] = $this->costOf($pid, $data, $assumptions, $memo, 0);
        }

        return $out;
    }

    /** Baris untuk halaman Asumsi Bahan: bahan beli + harga terakhirnya + asumsi yang berlaku. */
    public function assumptionRows(array $filters = []): Collection
    {
        $data        = $this->build($filters);
        $assumptions = MaterialPriceAssumption::map();

        $products = DB::table('products')
            ->whereIn('id', array_keys($data['leaves']))
            ->get(['id', 'sku', 'name'])
            ->keyBy('id');

        return collect($data['leaves'])
            ->map(function ($usedBy, $pid) use ($products, $data, $assumptions) {
                $p     = $products->get($pid);
                $price = $data['prices'][$pid] ?? null;
                $asumsi = $assumptions[$pid] ?? null;

                return [
                    'product'   => [
                        'id'   => (int) $pid,
                        'sku'  => $p->sku ?? '?',
                        'name' => $p->name ?? ('Produk #' . $pid),
                    ],
                    'price'     => $price['price'] ?? null,
                    'price_at'  => $price['date'] ?? null,
                    'source'    => $price['source'] ?? null,
                    'assumed'   => $asumsi,
                    'change'    => ($asumsi !== null && ($price['price'] ?? 0) > 0)
                        ? ($asumsi / $price['price'] - 1) * 100
                        : null,
                    'used_by'   => $usedBy,
                ];
            })
            ->sortByDesc('used_by')
            ->values();
    }

    // ==========================================================

    private function compute(array $filters): array
    {
        $sampleOps = $this->timeService->perProductSampleOrderIds($filters);

        $recipes = [];
        $sources = [];

        // Tingkat pertama: produk yang punya sampel — takarannya dari sampel yang sama
        // dengan yang dipakai waktu & biaya, supaya satu halaman tidak memakai dua kenyataan.
        foreach ($this->recipesFrom($sampleOps) as $pid => $lines) {
            $recipes[$pid] = $lines;
            $sources[$pid] = 'sampel';
        }

        // Turun terus selama masih ada bahan yang ternyata buatan sendiri.
        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $used = collect($recipes)->flatMap(fn ($lines) => array_keys($lines))->unique();
            $baru = $used->reject(fn ($id) => isset($recipes[$id]))->values()->all();

            if (empty($baru)) {
                break;
            }

            $found = $this->recipesFrom($this->recentOpsFor($baru));
            if (empty($found)) {
                break;
            }

            foreach ($found as $pid => $lines) {
                $recipes[$pid] = $lines;
                $sources[$pid] = 'op-terakhir';
            }
        }

        // Daun = bahan yang tidak punya resep: itulah yang dibeli, dan hanya itu yang boleh
        // diisi asumsinya.
        $leaves = [];
        foreach (array_keys($sampleOps) as $pid) {
            foreach ($this->leavesOf($pid, $recipes, [], 0) as $leaf) {
                $leaves[$leaf] = ($leaves[$leaf] ?? 0) + 1;
            }
        }

        $prices = $this->latestPrices(array_unique(array_merge(
            array_keys($leaves),
            collect($recipes)->flatMap(fn ($l) => array_keys($l))->unique()->all(),
        )));

        return compact('recipes', 'sources', 'prices', 'leaves');
    }

    /**
     * Takaran bahan per unit dari sekumpulan OP.
     *
     * CATATAN: OP yang menghasilkan produk sampingan membebankan seluruh bahannya ke produk
     * utama, karena pembagian per-produk baru dilakukan saat finalisasi. Untuk mengukur
     * dampak kenaikan harga bahan, melebihkan lebih aman daripada mengecilkan.
     *
     * @param  array<int,array<int,int>> $opsByProduct
     * @return array<int,array<int,float>>
     */
    private function recipesFrom(array $opsByProduct): array
    {
        $allOps = collect($opsByProduct)->flatten()->unique()->values()->all();
        if (empty($allOps)) {
            return [];
        }

        $produced = DB::table('stock_layers')
            ->where('source_type', 'production_order')
            ->whereIn('source_id', $allOps)
            ->where('qty_in', '>', 0)
            ->selectRaw('source_id, product_id, SUM(qty_in) as qty')
            ->groupBy('source_id', 'product_id')
            ->get();

        $materials = DB::table('production_order_materials')
            ->whereIn('production_order_id', $allOps)
            ->selectRaw('production_order_id, product_id, SUM(qty_consumed) as qty')
            ->groupBy('production_order_id', 'product_id')
            ->get();

        $out = [];

        foreach ($opsByProduct as $pid => $ops) {
            $ops    = array_map('intval', $ops);
            $qtyOut = (float) $produced->whereIn('source_id', $ops)->where('product_id', (int) $pid)->sum('qty');

            if ($qtyOut <= 0) {
                continue;
            }

            $lines = [];
            foreach ($materials->whereIn('production_order_id', $ops) as $m) {
                $mid = (int) $m->product_id;
                if ($mid === (int) $pid) {
                    continue; // OP lanjutan yang memakai barangnya sendiri — bukan bahan.
                }
                $lines[$mid] = ($lines[$mid] ?? 0) + (float) $m->qty / $qtyOut;
            }

            if (!empty($lines)) {
                $out[(int) $pid] = $lines;
            }
        }

        return $out;
    }

    /** @return array<int,array<int,int>> beberapa OP terakhir yang menghasilkan tiap produk */
    private function recentOpsFor(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return DB::table('stock_layers')
            ->where('source_type', 'production_order')
            ->whereIn('product_id', $productIds)
            ->where('qty_in', '>', 0)
            ->orderByDesc('id')
            ->get(['product_id', 'source_id'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('source_id')->unique()->take(self::RECENT_OPS)
                ->map(fn ($v) => (int) $v)->values()->all())
            ->filter()
            ->all();
    }

    /** @return int[] bahan beli yang menyusun sebuah produk, sedalam apa pun letaknya */
    private function leavesOf(int $pid, array $recipes, array $seen, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH || in_array($pid, $seen, true)) {
            return [];
        }

        if (!isset($recipes[$pid])) {
            return [$pid];
        }

        $seen[] = $pid;
        $out    = [];

        foreach (array_keys($recipes[$pid]) as $mid) {
            $out = array_merge($out, $this->leavesOf((int) $mid, $recipes, $seen, $depth + 1));
        }

        return array_unique($out);
    }

    /**
     * Biaya bahan per unit sebuah produk.
     *
     * Asumsi yang diisi operator MENGHENTIKAN penelusuran: kalau seseorang menuliskan harga
     * untuk sebuah barang, itu harga barang tersebut — bukan undangan menghitung ulang isinya.
     */
    private function costOf(int $pid, array $data, array $assumptions, array &$memo, int $depth): ?float
    {
        if (isset($assumptions[$pid])) {
            return (float) $assumptions[$pid];
        }
        if (array_key_exists($pid, $memo)) {
            return $memo[$pid];
        }
        if ($depth >= self::MAX_DEPTH) {
            return $data['prices'][$pid]['price'] ?? null;
        }

        if (!isset($data['recipes'][$pid])) {
            return $memo[$pid] = $data['prices'][$pid]['price'] ?? null;
        }

        $memo[$pid] = null; // penjaga lingkaran selama cabangnya masih dihitung
        $total      = 0.0;

        foreach ($data['recipes'][$pid] as $mid => $qty) {
            $harga = $this->costOf((int) $mid, $data, $assumptions, $memo, $depth + 1);
            if ($harga === null) {
                continue; // bahan tanpa harga: dilewati, bukan dianggap gratis diam-diam
            }
            $total += $qty * $harga;
        }

        return $memo[$pid] = $total > 0 ? $total : null;
    }

    /** @return array<int,array{price:float,date:?string,source:?string}> */
    private function latestPrices(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $layers = DB::table('stock_layers')
            ->whereIn('product_id', $productIds)
            ->where('qty_in', '>', 0)
            ->orderBy('id')
            ->get(['product_id', 'unit_cost', 'source_type', 'created_at']);

        $out = [];

        foreach ($layers->groupBy('product_id') as $pid => $rows) {
            // Lapisan PEMBELIAN terakhir lebih dipercaya daripada lapisan hasil produksi:
            // untuk bahan beli, itulah harga yang benar-benar dibayar terakhir kali.
            $row = $rows->where('source_type', 'purchase')->last() ?: $rows->last();

            $out[(int) $pid] = [
                'price'  => (float) $row->unit_cost,
                'date'   => $row->created_at ? substr((string) $row->created_at, 0, 10) : null,
                'source' => $row->source_type,
            ];
        }

        return $out;
    }
}
