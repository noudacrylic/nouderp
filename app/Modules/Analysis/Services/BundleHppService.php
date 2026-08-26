<?php

namespace App\Modules\Analysis\Services;

use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\ProductBundle;
use App\Modules\Analysis\Models\ProductPackingCost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Modules\Analysis\Support\AnalysisCache;

/**
 * HPP bundle — dirakit dari HPP produk Ready, bukan dihitung ulang dari nol.
 *
 *   HPP bundle = Σ (HPP komponen × jumlah per bundle)
 *              + Overhead Packing  (SEKALI, bukan per komponen)
 *              + Packing Khusus    milik bundle itu sendiri
 *
 * ── KENAPA OVERHEAD PACKING HANYA SEKALI ──────────────────────────────────
 *
 * Overhead packing pembaginya jumlah SURAT JALAN, bukan jumlah barang. Satu bundle berisi
 * tiga barang tetap dikirim sebagai satu paket, jadi kalau HPP komponen dijumlah apa adanya
 * — masing-masing sudah menanggung overhead — bundle akan dibebani ongkos membungkus tiga
 * kali. Karena itu yang diambil dari tiap komponen adalah HPP-nya DIKURANGI overhead
 * packing, lalu overhead ditambahkan sekali di tingkat bundle.
 *
 * Packing Khusus komponen tetap ikut: peti kayu untuk barang tertentu tetap dibutuhkan
 * walaupun barangnya sedang dijual dalam paket. Di atasnya, bundle boleh punya Packing
 * Khusus sendiri (kardus paket, kotak hampers) — diketik di baris tabelnya sendiri.
 *
 * ── KENAPA BUNDLE TIDAK PUNYA WAKTU PRODUKSI SENDIRI ──────────────────────
 *
 * Bundle tidak pernah punya OP: yang diproduksi komponennya. Waktu yang ditampilkan adalah
 * jumlah waktu komponen — dipakai untuk membaca berapa kapasitas pabrik yang terpakai untuk
 * satu bundle, bukan untuk menghitung fixed cost lagi (fixed cost sudah melekat di HPP tiap
 * komponen). Merakit isi paketnya sendiri ditanggung overhead packing.
 *
 * ── KOMPONEN YANG BUKAN BUATAN SENDIRI ────────────────────────────────────
 *
 * Komponen beli-jadi tidak punya sampel OP, jadi tidak muncul di halaman Ready. Untuk itu
 * dipakai harga perolehan terbaru dari kartu stok (`stock_layers`) sebagai variable cost,
 * dengan fixed cost nol — pabrik memang tidak mengeluarkan jam kerja untuknya. Barisnya
 * ditandai supaya jelas angkanya datang dari mana.
 *
 * CAKUPAN: sama seperti HPP Ready — angka analisa untuk menetapkan harga, TIDAK PERNAH
 * dijurnal.
 */
class BundleHppService
{
    public function __construct(
        protected ProductHppService $hpp,
        protected AnalysisCache $cache,
    ) {
    }

    /** @return Collection<int,array> dikunci product_id bundle */
    public function all(array $filters = []): Collection
    {
        return $this->cache->remember('bundle.all', $filters, fn () => $this->hitungSemua($filters));
    }

    protected function hitungSemua(array $filters): Collection
    {
        $bundles = DB::table('products')
            ->where('sale_type', 'bundle')
            ->when(!empty($filters['product_id']), fn ($q) => $q->where('id', $filters['product_id']))
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'base_price']);

        if ($bundles->isEmpty()) {
            return collect();
        }

        $components = $this->componentsByBundle($bundles->pluck('id')->map(fn ($v) => (int) $v)->all());
        $ready      = $this->hpp->all($filters);
        $basis      = $this->hpp->basis($filters);
        $packing    = ProductPackingCost::perUnitMap();

        $componentIds = collect($components)->flatten(1)->pluck('component_product_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();

        $meta     = $this->componentMeta($componentIds);
        $fallback = $this->latestStockCost(array_values(array_diff($componentIds, $ready->keys()->all())));

        return $bundles->mapWithKeys(fn ($b) => [
            (int) $b->id => $this->compose(
                $b,
                $components[(int) $b->id] ?? [],
                $ready,
                $meta,
                $fallback,
                $basis,
                $packing,
            ),
        ]);
    }

    public function forProduct(int $productId, array $filters = []): ?array
    {
        $filters['product_id'] = $productId;

        return $this->all($filters)->get($productId);
    }

    // ==========================================================

    protected function compose(
        object $bundle,
        array $rows,
        Collection $ready,
        array $meta,
        array $fallback,
        array $basis,
        array $packing,
    ): array {
        $bundleId = (int) $bundle->id;
        $lines    = [];
        $warnings = [];

        $variable  = 0.0;
        $fixed     = 0.0;
        $extraPack = 0.0;
        $seconds   = 0.0;
        $secKnown  = false;
        $retail    = 0.0;

        foreach ($rows as $row) {
            $pid  = (int) $row['component_product_id'];
            $qty  = (float) $row['qty'];
            $info = $meta[$pid] ?? null;
            $r    = $ready->get($pid);

            $line = [
                'product'        => $info ? ['id' => $info['id'], 'sku' => $info['sku'], 'name' => $info['name']]
                                          : ['id' => $pid, 'sku' => '?', 'name' => 'Produk #' . $pid],
                'qty'            => $qty,
                'variable_cost'  => null,
                'fixed_cost'     => null,
                'packing_khusus' => null,
                'sec_per_unit'   => null,
                'base_price'     => (float) ($info['base_price'] ?? 0),
                'source'         => null,
                'note'           => null,
            ];

            if ($r) {
                // Overhead packing sengaja TIDAK diambil dari komponen — ditambahkan sekali
                // di tingkat bundle, karena pembaginya surat jalan.
                $line['variable_cost']  = $r['variable_cost'];
                $line['fixed_cost']     = $r['fixed_cost'];
                $line['packing_khusus'] = $r['packing_khusus'];
                $line['sec_per_unit']   = $r['sec_per_unit_effective'];
                $line['source']         = 'HPP Ready';

                if ($r['variable_cost'] === null) {
                    $line['note'] = 'variable cost komponen belum diketahui';
                }
            } elseif (isset($fallback[$pid])) {
                $line['variable_cost'] = $fallback[$pid]['unit_cost'];
                $line['fixed_cost']    = 0.0;
                $line['source']        = 'kartu stok';
                $line['note']          = 'belum punya sampel produksi — dipakai harga perolehan terbaru';
            } else {
                $line['note'] = 'belum ada HPP maupun harga perolehan';
                $warnings[]   = "Komponen {$line['product']['name']} belum punya HPP maupun harga perolehan — HPP bundle kekurangan biaya sebesar isi komponen ini.";
            }

            if (($info['sale_type'] ?? null) === 'bundle') {
                $warnings[] = "Komponen {$line['product']['name']} sendiri sebuah bundle; isinya tidak dirakit bertingkat.";
            }

            $line['unit_cost'] = (float) $line['variable_cost'] + (float) $line['fixed_cost'] + (float) $line['packing_khusus'];
            $line['subtotal']  = $line['unit_cost'] * $qty;

            $variable  += (float) $line['variable_cost'] * $qty;
            $fixed     += (float) $line['fixed_cost'] * $qty;
            $extraPack += (float) $line['packing_khusus'] * $qty;
            $retail    += $line['base_price'] * $qty;

            if ($line['sec_per_unit'] !== null) {
                $seconds += $line['sec_per_unit'] * $qty;
                $secKnown = true;
            }

            $lines[] = $line;
        }

        if (empty($rows)) {
            $warnings[] = 'Bundle ini belum punya komponen. Lengkapi komponennya di halaman produk sebelum angka di sini dipakai.';
        }

        $packingOverhead = $basis['packing_per_transaction'];
        $packingKhusus   = $packing[$bundleId] ?? null;
        $packingTotal    = (float) $packingOverhead + (float) $packingKhusus;

        $hpp   = $variable + $fixed + $extraPack + $packingTotal;
        $price = (float) $bundle->base_price;

        return [
            'product' => ['id' => $bundleId, 'sku' => $bundle->sku, 'name' => $bundle->name],

            'components'      => $lines,
            'component_count' => count($lines),

            'variable_cost'            => $variable,
            'fixed_cost'               => $fixed,
            'component_packing_khusus' => $extraPack,
            'components_subtotal'      => $variable + $fixed + $extraPack,

            'sec_per_unit'     => $secKnown ? $seconds : null,
            'packing_overhead' => $packingOverhead,
            'packing_khusus'   => $packingKhusus,
            'packing_total'    => $packingTotal,

            'hpp_per_unit' => $hpp,
            'base_price'   => $price,
            'margin'       => $price > 0 ? $price - $hpp : null,

            // Dua ukuran yang sama dengan halaman Ready, supaya keduanya bisa dibaca
            // berdampingan tanpa perlu menghitung ulang di kepala.
            'margin_percent' => $price > 0 ? ($price - $hpp) / $price * 100 : null,
            'markup_percent' => ($price > 0 && $hpp > 0) ? ($price - $hpp) / $hpp * 100 : null,

            // Harga komponen kalau dibeli terpisah — pembanding untuk menakar seberapa besar
            // potongan yang sebenarnya diberikan lewat bundling.
            'components_price_total' => $retail,
            'bundle_discount'        => ($price > 0 && $retail > 0) ? $retail - $price : null,

            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Komponen per bundle. `bundle_components` didahulukan, `product_bundles` jadi cadangan —
     * urutan yang sama dengan BundleService, supaya HPP membaca isi paket yang persis sama
     * dengan yang benar-benar dipotong dari stok saat bundle dikirim.
     *
     * @param  int[]  $bundleIds
     * @return array<int,array<int,array{component_product_id:int, qty:float}>>
     */
    protected function componentsByBundle(array $bundleIds): array
    {
        $out = [];

        foreach (BundleComponent::whereIn('bundle_product_id', $bundleIds)->get() as $c) {
            $out[(int) $c->bundle_product_id][] = [
                'component_product_id' => (int) $c->component_product_id,
                'qty'                  => (float) ($c->qty ?? 1),
            ];
        }

        $missing = array_values(array_diff($bundleIds, array_keys($out)));
        if (!empty($missing)) {
            foreach (ProductBundle::whereIn('bundle_product_id', $missing)->get() as $c) {
                $out[(int) $c->bundle_product_id][] = [
                    'component_product_id' => (int) $c->component_product_id,
                    'qty'                  => (float) ($c->qty_required ?? 1),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  int[]  $ids
     * @return array<int,array{id:int,sku:string,name:string,base_price:float,sale_type:string}>
     */
    protected function componentMeta(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return DB::table('products')->whereIn('id', $ids)
            ->get(['id', 'sku', 'name', 'base_price', 'sale_type'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => [
                'id'         => (int) $p->id,
                'sku'        => $p->sku,
                'name'       => $p->name,
                'base_price' => (float) $p->base_price,
                'sale_type'  => $p->sale_type,
            ]])
            ->all();
    }

    /**
     * Harga perolehan terbaru dari kartu stok, apa pun sumbernya (pembelian, saldo awal,
     * produksi). Hanya dipakai untuk komponen yang tidak muncul di HPP Ready.
     *
     * Yang terbaru, bukan rata-rata — alasannya sama dengan di ProductHppService: harga
     * bahan bergerak, dan angka ini dipakai untuk menetapkan harga ke depan.
     *
     * @param  int[]  $ids
     * @return array<int,array{unit_cost:float, source_type:?string}>
     */
    protected function latestStockCost(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $latestIds = DB::table('stock_layers')
            ->whereIn('product_id', $ids)
            ->where('qty_in', '>', 0)
            ->selectRaw('MAX(id) as id')
            ->groupBy('product_id')
            ->pluck('id')
            ->all();

        if (empty($latestIds)) {
            return [];
        }

        return DB::table('stock_layers')
            ->whereIn('id', $latestIds)
            ->get(['product_id', 'unit_cost', 'source_type'])
            ->mapWithKeys(fn ($l) => [(int) $l->product_id => [
                'unit_cost'   => (float) $l->unit_cost,
                'source_type' => $l->source_type,
            ]])
            ->all();
    }
}
