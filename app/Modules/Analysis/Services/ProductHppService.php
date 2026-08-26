<?php

namespace App\Modules\Analysis\Services;

use App\Modules\Analysis\Models\ProductPackingCost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Modules\Analysis\Support\AnalysisCache;

/**
 * HPP (harga pokok) per unit produk — empat suku, tidak lebih.
 *
 *   HPP/unit = Variable Cost      dari WIP (lapisan stok hasil OP sampel)
 *            + Fixed Cost         tarif per slot-jam × waktu produksi per unit
 *            + Overhead Packing   biaya packing sebulan ÷ jumlah surat jalan
 *            + Packing Khusus     diketik sendiri per produk (kardus khusus / peti kayu)
 *
 * ── KENAPA TARIF GABUNGAN ─────────────────────────────────────────────────
 *
 * Satu tarif Rp/slot-jam untuk semua divisi, bukan tarif per divisi. Keputusan pemilik
 * (20 Agu 2026) setelah diberi tahu konsekuensinya: satu jam Assembling sebenarnya ±1,8×
 * lebih mahal dari satu jam CNC, jadi produk yang berat di CNC ikut menanggung sedikit
 * lebih banyak dan sebaliknya. Untungnya: pemecahan biaya per divisi jadi tidak
 * berpengaruh sama sekali ke HPP, sehingga halaman Fixed Cost cukup menampilkan jumlah.
 *
 * ── KENAPA PEMBAGINYA JAM TERSEDIA ────────────────────────────────────────
 *
 * Bukan jam terpakai. Jam yang dibayar tetap dibayar walau menganggur — itulah arti fixed
 * cost. Akibatnya biaya TIDAK terserap habis: selisihnya adalah biaya kapasitas menganggur,
 * dilaporkan di baris rekonsiliasi apa adanya. Kalau pembaginya jam terpakai, seluruh biaya
 * akan terserap habis tapi HPP jadi naik-turun ikut sepi-ramainya bulan — bulan sepi
 * menaikkan HPP, menaikkan harga, membuat bulan berikutnya lebih sepi lagi.
 *
 * ── KENAPA PACKING PUNYA SUKU SENDIRI ─────────────────────────────────────
 *
 * Membungkus barang tidak jadi lebih lama karena barangnya lebih lama dibuat, jadi biaya
 * packing tidak boleh ikut dikali waktu. Pembaginya jumlah surat jalan. Di atasnya ada
 * Packing Khusus yang diketik sendiri — DITAMBAHKAN, bukan menimpa, karena peti kayu adalah
 * biaya EKSTRA di atas ongkos membungkus yang biasa.
 *
 * ── WAKTU YANG DIPAKAI ────────────────────────────────────────────────────
 *
 * Waktu EFEKTIF dari ProductionTimeInsightService: hasil pengukuran, kecuali kalau operator
 * mencentang asumsi di halaman Waktu Produksi. Jadi "kalau assembling dipercepat, HPP jadi
 * berapa" langsung terjawab di sini tanpa mengubah data terukurnya.
 *
 * CAKUPAN: angka ini untuk analisa harga jual & margin — TIDAK PERNAH dijurnal.
 * Akuntansi mencatat apa adanya; halaman ini mengandaikan.
 */
class ProductHppService
{
    public function __construct(
        protected ProductionTimeAnalysisService $timeService,
        protected ProductionTimeInsightService $insight,
        protected ProductionQuotaService $quotaService,
        protected MaterialRecipeService $recipes,
        protected AnalysisCache $cache,
    ) {
    }

    /**
     * @return Collection<int,array> dikunci product_id
     *
     * Hasilnya disimpan sampai ADA DATA YANG BERUBAH — lihat AnalysisCache. Terukur
     * sebelum ini: 1,4 detik & 1.172 query setiap kali halaman HPP dibuka, padahal
     * angkanya sama persis dengan yang tadi.
     */
    public function all(array $filters = []): Collection
    {
        return $this->cache->remember('hpp.all', $filters, fn () => $this->hitungSemua($filters));
    }

    protected function hitungSemua(array $filters): Collection
    {
        $timeRows = $this->timeService->perProduct($filters);
        if ($timeRows->isEmpty()) {
            return collect();
        }

        $rows      = collect($this->insight->enrich($timeRows, $filters));
        $basis     = $this->basis($filters);
        $materials = $this->materialCostPerUnit($rows->keys()->all(), $filters);
        $packing   = ProductPackingCost::perUnitMap();
        $prices    = DB::table('products')->whereIn('id', $rows->keys())->pluck('base_price', 'id');

        // Dua harga bahan pembanding: harga beli terakhir, dan harga yang diandaikan operator.
        // Keduanya dihitung ulang dari resep, jadi selisihnya terhadap variable cost tercatat
        // sekaligus memberi tahu seberapa basi lapisan stok yang dipakai sampel.
        $today   = $this->recipes->costs($filters, withAssumption: false);
        $assumed = $this->recipes->costs($filters, withAssumption: true);

        return $rows->map(fn ($row) => $this->compose(
            $row,
            $basis,
            $materials[$row['product']['id']] ?? null,
            $packing[$row['product']['id']] ?? null,
            (float) ($prices[$row['product']['id']] ?? 0),
            $today[$row['product']['id']] ?? null,
            $assumed[$row['product']['id']] ?? null,
            (bool) ($filters['assumption'] ?? false),
        ));
    }

    public function forProduct(int $productId, array $filters = []): ?array
    {
        $filters['product_id'] = $productId;

        return $this->all($filters)->get($productId);
    }

    /**
     * Angka dasar yang sama untuk semua produk: tarif per slot-jam, tarif packing, dan
     * bahan rekonsiliasi.
     */
    public function basis(array $filters = []): array
    {
        $q = $this->quotaService->build($filters);

        return $q['cost'] + [
            'slot_count'  => $q['totals']['slot_count'],
            'used_hours'  => $q['totals']['used_month'],
            'utilization' => $q['totals']['utilization'],
        ];
    }

    // ==========================================================

    protected function compose(
        array $row,
        array $basis,
        ?array $material,
        ?float $packingKhusus,
        float $price,
        ?float $variableToday = null,
        ?float $variableAssumed = null,
        bool $useAssumption = false,
    ): array {
        $sec  = $row['sec_per_unit_effective'] ?? $row['total']['sec_per_unit'];
        $rate = $basis['rate_per_slot_hour'];

        $fixed = ($sec !== null && $rate !== null) ? ($sec / 3600) * $rate : null;

        $packingOverhead = $basis['packing_per_transaction'];
        // Ditambahkan, bukan menimpa: peti kayu adalah biaya EKSTRA di atas ongkos
        // membungkus yang biasa, bukan penggantinya.
        $packingTotal = (float) $packingOverhead + (float) $packingKhusus;

        $recorded = $material['cost_per_unit'] ?? null;

        // Mode asumsi memakai harga bahan yang diandaikan; kalau resep produk ini belum
        // ketahuan, jatuh kembali ke angka tercatat — lebih baik satu baris tertinggal di
        // harga lama daripada seluruh halaman menolak menampilkan angka.
        $variable = $useAssumption ? ($variableAssumed ?? $recorded) : $recorded;
        $hpp      = (float) $variable + (float) $fixed + $packingTotal;

        $warnings = [];
        if ($useAssumption && $variableAssumed === null) {
            $warnings[] = 'Resep bahan produk ini belum ketahuan dari OP sampelnya, jadi harga asumsi tidak berlaku di sini — angkanya masih variable cost tercatat.';
        }
        if ($variable === null) {
            $warnings[] = 'Variable cost belum diketahui — belum ada hasil produksi tersimpan di kartu stok untuk OP sampel ini.';
        }
        if ($sec === null) {
            $warnings[] = 'Waktu produksi per unit belum diketahui — cek Waktu Produksi, atau isi asumsinya di sana.';
        }
        if ($rate === null) {
            $warnings[] = 'Tarif per slot-jam belum bisa dihitung — kapasitas di Kuota Produksi masih nol.';
        }
        if ($row['qty_per_cycle'] === null) {
            $warnings[] = 'Hasil per siklus tidak diketahui (sampel tanpa BOM), jadi waktu per unit tidak bisa dihitung.';
        }

        return [
            'product'            => $row['product'],

            'sec_per_unit'          => $row['total']['sec_per_unit'],
            'sec_per_unit_effective'=> $sec,
            'has_assumption'        => (bool) ($row['has_assumption'] ?? false),
            'per_division'          => $row['per_division'],

            'variable_cost'      => $variable,
            'material'           => $material,

            // Tiga angka bersanding: yang tercatat di kartu stok, yang berlaku kalau semua
            // bahan dinilai dengan harga beli terakhir, dan yang berlaku kalau harga andaian
            // dipakai. `variable_cost` di atas adalah salah satunya, tergantung mode.
            'variable_recorded'  => $recorded,
            'variable_today'     => $variableToday,
            'variable_assumed'   => $variableAssumed,
            'variable_mode'      => $useAssumption ? 'asumsi' : 'tercatat',

            'fixed_cost'         => $fixed,
            'rate_per_slot_hour' => $rate,

            'packing_overhead'   => $packingOverhead,
            'packing_khusus'     => $packingKhusus,
            'packing_total'      => $packingTotal,

            'hpp_per_unit'       => $hpp,
            'base_price'         => $price,
            'margin'             => $price > 0 ? $price - $hpp : null,

            // DUA ukuran, sengaja keduanya ditampilkan karena menjawab pertanyaan berbeda:
            //  • margin  = laba ÷ HARGA JUAL  → "dari tiap Rp 100 penjualan, berapa yang laba"
            //  • markup  = laba ÷ HPP         → "modalnya dikalikan berapa"  ← kebiasaan pemilik
            // Margin tidak pernah bisa lewat 100%; markup bisa berapa saja. Menampilkan satu
            // saja membuat angkanya terasa keliru bagi yang terbiasa menghitung dengan yang lain.
            'margin_percent'     => $price > 0 ? ($price - $hpp) / $price * 100 : null,
            'markup_percent'     => ($price > 0 && $hpp > 0) ? ($price - $hpp) / $hpp * 100 : null,

            'capacity_per_day'   => $row['capacity_per_day'] ?? null,
            'bottleneck_name'    => $row['bottleneck_name'] ?? null,
            'sample_count'       => $row['included_count'] ?? 0,
            'qty_per_cycle'      => $row['qty_per_cycle'],
            'warnings'           => $warnings,
        ];
    }

    /**
     * Variable cost per unit = `stock_layers.unit_cost` dari lapisan stok TERBARU yang
     * lahir dari OP sampel produk tsb.
     *
     * Yang terbaru, bukan rata-rata seluruh sampel: harga bahan bergerak, dan HPP
     * dipakai untuk menetapkan harga jual ke depan — rata-rata beberapa bulan akan
     * membuatnya tertinggal dari harga beli terkini.
     *
     * @param  int[]  $productIds
     * @return array<int,array{cost_per_unit: float, qty: float, layers: int, orders: int, avg_cost_per_unit: float}>
     */
    public function materialCostPerUnit(array $productIds, array $filters): array
    {
        if (empty($productIds)) {
            return [];
        }

        // Ambil OP sampel terpakai per produk dari service waktu, supaya biaya bahan
        // memakai sampel yang sama dengan waktu (termasuk pengecualian operator).
        $orderIdsByProduct = [];
        foreach ($this->timeService->perProductSampleOrderIds($filters) as $pid => $orderIds) {
            if (in_array($pid, $productIds, true)) {
                $orderIdsByProduct[$pid] = $orderIds;
            }
        }

        $allOrderIds = collect($orderIdsByProduct)->flatten()->unique()->values()->all();
        if (empty($allOrderIds)) {
            return [];
        }

        $layers = DB::table('stock_layers')
            ->where('source_type', 'production_order')
            ->whereIn('source_id', $allOrderIds)
            ->whereIn('product_id', $productIds)
            ->where('qty_in', '>', 0)
            ->orderBy('id')
            ->get(['id', 'product_id', 'source_id', 'qty_in', 'unit_cost']);

        $out = [];
        foreach ($layers->groupBy('product_id') as $pid => $rows) {
            $allowed = $orderIdsByProduct[$pid] ?? [];
            $rows    = $rows->filter(fn ($l) => in_array((int) $l->source_id, $allowed, true));
            if ($rows->isEmpty()) {
                continue;
            }

            $qty   = (float) $rows->sum('qty_in');
            $value = (float) $rows->sum(fn ($l) => (float) $l->qty_in * (float) $l->unit_cost);

            // id terbesar = lapisan yang lahir paling akhir; tanggal tidak dipakai karena
            // beberapa lapisan bisa lahir pada hari yang sama dari OP berbeda.
            $latest = $rows->sortBy('id')->last();

            $out[(int) $pid] = [
                'cost_per_unit'     => (float) $latest->unit_cost,
                'avg_cost_per_unit' => $qty > 0 ? $value / $qty : 0.0,
                'qty'               => $qty,
                'layers'            => $rows->count(),
                'orders'            => $rows->pluck('source_id')->unique()->count(),
            ];
        }

        return $out;
    }

    /**
     * Rekonsiliasi: berapa fixed cost yang benar-benar terserap ke barang, dibanding total
     * fixed cost sebulan.
     *
     * Ada supaya modelnya bisa diperiksa. Kalau keduanya jauh melenceng, itu tanda waktu per
     * unit dan kuota diambil dari data yang tidak sama — bukan sesuatu yang akan terlihat
     * sebagai angka salah, melainkan sebagai HPP yang "rasanya kurang pas" tanpa bisa
     * ditunjuk di mana.
     */
    public function reconciliation(array $filters = []): array
    {
        $b = $this->basis($filters);

        return [
            'fixed_total'        => $b['fixed_total'],
            'available_hours'    => $b['available_hours'],
            'used_hours'         => $b['used_hours'],
            'utilization'        => $b['utilization'],
            'rate_per_slot_hour' => $b['rate_per_slot_hour'],
            'absorbed'           => $b['absorbed'],
            'unabsorbed'         => $b['unabsorbed'],
            'unabsorbed_percent' => $b['unabsorbed_percent'],
            'packing_total'      => $b['packing_total'],
            'packing_per_transaction' => $b['packing_per_transaction'],
            'transactions_per_month'  => $b['transactions_per_month'],
        ];
    }
}
