<?php

namespace App\Modules\Analysis\Services;

use App\Modules\Analysis\CustomPrice\Recipe;
use App\Modules\Analysis\CustomPrice\Recipes\KotakRecipe;
use App\Modules\Analysis\CustomPrice\Recipes\MaharRecipe;
use App\Modules\Analysis\Models\CustomPriceCategory;
use App\Modules\Analysis\Models\CustomPriceMaterial;
use App\Modules\Analysis\Models\CustomPriceSample;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Harga Custom — satu rumus untuk semua produk custom; tiap jenis produk hanya beda resep.
 *
 *   HPP/unit = bahan    Σ(luas panel × Rp/cm² bahannya) × (1 + % sisa)
 *            + aksesoris
 *            + waktu    (detik CNC + detik rakit) ÷ 3600 × tarif per slot-jam
 *            + packing  overhead per surat jalan (sama dengan HPP Ready) + kayu (opsional) + khusus
 *            + setup    biaya setting per pesanan ÷ qty
 *   Non-MP   = HPP × (1 + markup)
 *   MP       = (HPP × (1 + markup) + biaya tetap kanal) ÷ (1 − potongan kanal − tambahan custom)
 *              → untung bersih sama di semua kanal
 *   Semua harga dibulatkan KE ATAS berakhiran 900 (pola harga toko).
 *
 * Rumus waktu disepakati pemilik 25 Nov 2026 dari timer Mahar ready + Tempat Obat:
 *   detik CNC   = Σ keliling panel ÷ kecepatan potong bahannya (2 mm = 25 mm/dtk)
 *               + persiapan & ambil komponen per lembar × (luas panel ÷ luas lembar)
 *   detik rakit = 15 dtk × komponen dilem + 2,2 dtk × cm lem + 60 dtk × aksesoris
 * Angka ini "perkiraan sementara": sejak 25 Nov ERP membaca kapan mesin benar-benar memotong,
 * jadi kecepatan & persiapan nanti bisa diukur langsung. Faktor sampel (halaman Faktor Waktu)
 * tetap bisa menggeser tingkatnya; tanpa sampel faktornya 1.
 *
 * Tarif per slot-jam dan kapasitas per hari diambil dari Kuota Produksi — angka yang SAMA yang
 * dipakai HPP produk Ready, supaya harga custom dan reguler bisa dibandingkan apel dengan apel.
 *
 * Modul Analisa tidak pernah menyentuh jurnal. Semua di sini informasi.
 */
class CustomPriceService
{
    /** Parameter waktu, berlaku untuk semua resep. Nilai awal dari spreadsheet pemilik. */
    public const TIME_PARAMS = [
        'cnc_menit_per_lembar'      => ['label' => 'CNC: persiapan & ambil komponen per lembar penuh', 'default' => 30,  'unit' => 'menit/lembar'],
        'rakit_detik_per_komponen'  => ['label' => 'Rakit: memposisikan per komponen',                 'default' => 15,  'unit' => 'detik/komponen'],
        'rakit_detik_per_cm_lem'    => ['label' => 'Rakit: per cm sambungan lem',                      'default' => 2.2, 'unit' => 'detik/cm'],
        'rakit_detik_per_aksesoris' => ['label' => 'Rakit: per aksesoris',                             'default' => 60,  'unit' => 'detik/pcs'],
    ];

    /**
     * Packing kayu — rumus sheet pemilik untuk Mahar ready. Ukuran peti = sisi pendek + 2,
     * sisi panjang + 4, tebal + 2; atas-bawah dan samping punya harga per cm² sendiri.
     * Kardus sengaja tidak ada: kardus ukuran custom tidak mungkin dibeli.
     */
    public const PACKING_PARAMS = [
        'kemasan_tambah_pendek'  => ['label' => 'Peti: tambahan sisi pendek',      'default' => 2,     'unit' => 'cm'],
        'kemasan_tambah_panjang' => ['label' => 'Peti: tambahan sisi panjang',     'default' => 4,     'unit' => 'cm'],
        'kemasan_tambah_tebal'   => ['label' => 'Peti: tambahan tebal',            'default' => 2,     'unit' => 'cm'],
        'kayu_rp_atas_bawah'     => ['label' => 'Kayu atas & bawah',               'default' => 4.464, 'unit' => 'Rp/cm²'],
        'kayu_rp_samping'        => ['label' => 'Kayu samping',                    'default' => 5.456, 'unit' => 'Rp/cm²'],
        'kayu_biaya_proses'      => ['label' => 'Kayu: biaya proses per peti',     'default' => 10000, 'unit' => 'Rp'],
    ];

    /**
     * Harga grosir terkunci (28 Nov 2026): di bawah minimal order = harga 1 pcs (lebih mahal),
     * minimal order s/d sebelum grosir = harga dihitung pada qty minimal order, grosir ke atas =
     * harga dihitung pada qty grosir. Harga per kelompok TIDAK ikut berubah dengan qty pesanan.
     */
    public const GROSIR_PARAMS = [
        'grosir_minimal_order' => ['label' => 'Grosir: minimal order (harga normal mulai)', 'default' => 2, 'unit' => 'pcs'],
        'grosir_mulai'         => ['label' => 'Grosir: harga grosir mulai',                 'default' => 6, 'unit' => 'pcs'],
    ];

    /** Potongan tambahan khusus produk custom per kanal (Shopee & TikTok +3%, 25 Nov 2026). */
    public const DEFAULT_EXTRA_FEE = ['shopee' => 3, 'tiktok_tokopedia' => 3];

    /** Luas lembar kalau ukuran lembar bahannya belum diisi (122 × 244 cm). */
    public const DEFAULT_SHEET_AREA = 122 * 244;

    /**
     * Kecepatan potong (mm/detik) per tebal — kolom "Cut" di sheet FC spreadsheet pemilik.
     * Dipakai kalau bahan belum diisi kecepatannya sendiri di Pengaturan.
     */
    public const DEFAULT_SPEEDS = [1.5 => 30, 2 => 25, 3 => 18, 5 => 10, 10 => 5];

    /** Kecepatan kalau tebalnya pun tidak diketahui — pakai yang lambat (3 mm), aman. */
    public const FALLBACK_SPEED = 18;

    /** Minimal order = kapasitas seluruh produksi selama setengah hari kerja. */
    public const MIN_ORDER_DAY_FRACTION = 0.5;

    /** Berat volumetrik = (P+6)(L+5)(T+5) ÷ 5000 — ketentuan pemilik 28 Nov 2026. */
    public const VOLUMETRIC_DIVISOR = 5000;
    public const VOLUMETRIC_ADD     = ['p' => 6, 'l' => 5, 't' => 5];
    public const DENSITY_G_CM3      = 1.19;   // massa jenis akrilik

    public const TIER_QTYS = [1, 5, 10, 20, 50];

    public function __construct(
        protected ProductionQuotaService $quota,
        protected MaterialRecipeService $materialPrices,
        protected ChannelPricingService $channelPricing,
        protected ProductHppService $productHpp,
    ) {
    }

    // ── Resep & kategori ─────────────────────────────────────────────────────────

    /** @return array<string,Recipe> */
    public function recipes(): array
    {
        $out = [];
        foreach ([new MaharRecipe(), new KotakRecipe()] as $r) {
            $out[$r->code()] = $r;
        }

        return $out;
    }

    public function recipe(CustomPriceCategory $category): Recipe
    {
        $recipe = $this->recipes()[$category->code] ?? null;
        abort_if($recipe === null, 404, 'Resep untuk kategori ini belum dibuat.');

        return $recipe;
    }

    /** Kategori yang resepnya sudah ada. */
    public function categories(): Collection
    {
        return CustomPriceCategory::whereIn('code', array_keys($this->recipes()))->orderBy('id')->get();
    }

    /** @return array<string,array{label:string,default:float,unit:string}> */
    public function paramDefinitions(CustomPriceCategory $category): array
    {
        return self::TIME_PARAMS + self::PACKING_PARAMS + self::GROSIR_PARAMS + $this->extraFeeParams() + $this->recipe($category)->params();
    }

    /** Satu parameter "potongan tambahan custom" per kanal marketplace di config. */
    private function extraFeeParams(): array
    {
        $out = [];
        foreach (config('price_channels', []) as $key => $ch) {
            if (($ch['kind'] ?? 'marketplace') !== 'marketplace') {
                continue;
            }
            $out['potongan_tambahan_' . $key] = [
                'label'   => 'Potongan tambahan custom: ' . ($ch['label'] ?? $key),
                'default' => (float) (self::DEFAULT_EXTRA_FEE[$key] ?? 0),
                'unit'    => '%',
            ];
        }

        return $out;
    }

    /** Nilai parameter yang berlaku: tersimpan, atau bawaan kalau belum pernah diisi. */
    public function params(CustomPriceCategory $category): array
    {
        $saved = $category->params ?? [];
        $out   = [];
        foreach ($this->paramDefinitions($category) as $key => $def) {
            $out[$key] = isset($saved[$key]) && is_numeric($saved[$key]) ? (float) $saved[$key] : (float) $def['default'];
        }

        return $out;
    }

    // ── Bahan ────────────────────────────────────────────────────────────────────

    /**
     * Bahan lembaran yang bisa dipilih — daftar Bahan Baku di Pengaturan Produksi (yang sudah
     * punya ukuran lembar), plus harga per cm², tebal, dan kecepatan potongnya.
     *
     * @return Collection<int,array>
     */
    public function materials(): Collection
    {
        $rows = DB::table('production_raw_materials as rm')
            ->join('products as p', 'p.id', '=', 'rm.product_id')
            ->leftJoin('custom_price_materials as cm', 'cm.product_id', '=', 'rm.product_id')
            ->orderBy('p.name')
            ->get([
                'rm.product_id', 'rm.panjang', 'rm.lebar', 'p.sku', 'p.name', 'p.last_cost', 'p.cost_price',
                'cm.tebal_mm', 'cm.cut_speed',
            ]);

        $prices = $this->productPrices($rows->pluck('product_id')->all());

        return $rows->mapWithKeys(function ($r) use ($prices) {
            $area  = (float) $r->panjang * (float) $r->lebar;
            $price = $prices[(int) $r->product_id] ?? ['price' => null, 'date' => null];

            $tebal       = $r->tebal_mm !== null ? (float) $r->tebal_mm : $this->parseTebal($r->name);
            $tebalSource = $r->tebal_mm !== null ? 'diisi' : ($tebal !== null ? 'dari nama' : null);
            $speed       = $r->cut_speed !== null ? (float) $r->cut_speed : $this->defaultSpeed($tebal);
            $speedSource = $r->cut_speed !== null ? 'diisi' : ($tebal !== null ? 'bawaan tebal' : 'cadangan');

            return [(int) $r->product_id => [
                'product_id'    => (int) $r->product_id,
                'sku'           => $r->sku,
                'name'          => $r->name,
                'sheet_p'       => (float) $r->panjang,
                'sheet_l'       => (float) $r->lebar,
                'sheet_area'    => $area,
                'price'         => $price['price'],
                'price_date'    => $price['date'],
                'price_per_cm2' => ($price['price'] !== null && $area > 0) ? $price['price'] / $area : null,
                'tebal_mm'      => $tebal,
                'tebal_source'  => $tebalSource,
                'cut_speed'     => $speed,
                'speed_source'  => $speedSource,
                'tebal_saved'   => $r->tebal_mm !== null ? (float) $r->tebal_mm : null,
                'speed_saved'   => $r->cut_speed !== null ? (float) $r->cut_speed : null,
            ]];
        });
    }

    /** "Akrilik Lembaran 2 mm Bening" → 2.0; "1,5 mm" → 1.5. */
    public function parseTebal(?string $name): ?float
    {
        if ($name && preg_match('/(\d+(?:[.,]\d+)?)\s*mm/i', $name, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }

    /** Kecepatan bawaan untuk tebal tertentu: titik terdekat di tabel spreadsheet. */
    public function defaultSpeed(?float $tebal): float
    {
        if ($tebal === null || $tebal <= 0) {
            return self::FALLBACK_SPEED;
        }

        $best = null;
        foreach (self::DEFAULT_SPEEDS as $t => $speed) {
            if ($best === null || abs($t - $tebal) < abs($best[0] - $tebal)) {
                $best = [$t, $speed];
            }
        }

        return (float) $best[1];
    }

    /**
     * Harga beli terakhir: lapisan stok pembelian terakhir; kalau belum pernah ada lapisan,
     * jatuh ke last_cost / cost_price produk.
     *
     * @return array<int,array{price:?float,date:?string}>
     */
    public function productPrices(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }

        $layers = $this->materialPrices->latestPrices($productIds);
        $fallback = DB::table('products')->whereIn('id', $productIds)->get(['id', 'last_cost', 'cost_price'])->keyBy('id');

        $out = [];
        foreach ($productIds as $id) {
            if (isset($layers[$id]) && $layers[$id]['price'] > 0) {
                $out[$id] = ['price' => (float) $layers[$id]['price'], 'date' => $layers[$id]['date']];
                continue;
            }
            $p = $fallback[$id] ?? null;
            $v = $p ? (float) ($p->last_cost ?: $p->cost_price) : 0.0;
            $out[$id] = ['price' => $v > 0 ? $v : null, 'date' => null];
        }

        return $out;
    }

    // ── Geometri → bahan & waktu dasar ────────────────────────────────────────────

    /**
     * Ubah input mentah jadi daftar panel lengkap dengan bahan, luas, keliling, biaya bahan,
     * aksesoris berharga, dan waktu DASAR (sebelum dikali faktor).
     */
    public function geometry(CustomPriceCategory $category, array $raw, ?Collection $materials = null): array
    {
        $recipe    = $this->recipe($category);
        $params    = $this->params($category);
        $materials ??= $this->materials();

        $input  = $recipe->normalize($raw);
        $errors = $recipe->validate($input);

        $bahan = $this->normalizeMaterialChoice($raw['bahan'] ?? [], $recipe, $materials);
        if (!$bahan['utama']) {
            $errors[] = 'Pilih bahan utama.';
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'input' => $input, 'bahan' => $bahan];
        }

        $built    = $recipe->build($input, $params);
        $waste    = $category->waste_percent / 100;
        $warnings = [];

        $panels      = [];
        $materialRaw = 0.0;
        $cncPotong   = 0.0;
        $cncSiap     = 0.0;
        $cutMm       = 0.0;
        $areaTotal   = 0.0;
        $weightG     = 0.0;

        foreach ($built['panels'] as $pn) {
            $mid = $bahan[$pn['role']] ?? $bahan['utama'];
            $m   = $materials[$mid] ?? null;

            $area     = $pn['p'] * $pn['l'] * $pn['qty'];
            $keliling = 2 * ($pn['p'] + $pn['l']) * 10 * $pn['qty'];   // mm
            $speed    = $m['cut_speed'] ?? self::FALLBACK_SPEED;
            $cost     = ($m && $m['price_per_cm2'] !== null) ? $area * $m['price_per_cm2'] : null;

            if ($m && $m['price_per_cm2'] === null) {
                $warnings['harga:' . $mid] = "Harga {$m['name']} belum diketahui — biaya bahannya terhitung Rp0.";
            }
            if ($m && !$this->fitsSheet($pn['p'], $pn['l'], $m)) {
                $warnings['lembar:' . $pn['bagian']] = "Panel {$pn['bagian']} ({$pn['p']}×{$pn['l']} cm) lebih besar dari lembar {$m['name']} — perlu disambung.";
            }

            // Persiapan & ambil komponen dihitung per lembar, lalu dibagi menurut luas yang dipakai.
            $sheetArea = ($m['sheet_area'] ?? 0) > 0 ? $m['sheet_area'] : self::DEFAULT_SHEET_AREA;

            $materialRaw += (float) $cost;
            $cncPotong   += $keliling / max($speed, 0.1);
            $cncSiap     += $params['cnc_menit_per_lembar'] * 60 * $area / $sheetArea;
            $cutMm       += $keliling;
            $areaTotal   += $area;
            $weightG     += $area * (($m['tebal_mm'] ?? 0) / 10) * self::DENSITY_G_CM3;

            $panels[] = $pn + [
                'material_id'   => $mid,
                'material_name' => $m['name'] ?? '—',
                'area'          => $area,
                'keliling_mm'   => $keliling,
                'price_per_cm2' => $m['price_per_cm2'] ?? null,
                'cost'          => $cost,
            ];
        }

        // Aksesoris dari produk ERP mengikuti harga beli terakhirnya.
        $prices = $this->productPrices(array_column($built['accessories'], 'product_id'));
        $productNames = DB::table('products')->whereIn('id', array_filter(array_column($built['accessories'], 'product_id')))->pluck('name', 'id');
        $accessories = [];
        $accCost = 0.0;
        foreach ($built['accessories'] as $a) {
            $unit = $a['unit_price'];
            if ($a['product_id']) {
                $unit = $prices[$a['product_id']]['price'] ?? null;
                $a['name'] = $productNames[$a['product_id']] ?? $a['name'];
                if ($unit === null) {
                    $warnings['acc:' . $a['product_id']] = "Harga {$a['name']} belum diketahui — terhitung Rp0.";
                }
            }
            $a['unit_price'] = $unit;
            $a['total']      = (float) $unit * $a['qty'];
            $accCost        += $a['total'];
            $accessories[]   = $a;
        }

        $komponen = (float) ($built['komponen'] ?? array_sum(array_column($built['panels'], 'qty')));

        $cncBase = $cncPotong + $cncSiap;
        $asmBase = $komponen * $params['rakit_detik_per_komponen']
            + $built['lem_cm'] * $params['rakit_detik_per_cm_lem']
            + $built['accessory_count'] * $params['rakit_detik_per_aksesoris'];

        $o = $built['outer'];

        return [
            'ok'              => true,
            'errors'          => [],
            'input'           => $input,
            'bahan'           => $bahan,
            'description'     => $built['description'],
            'panels'          => $panels,
            'accessories'     => $accessories,
            'area_total'      => $areaTotal,
            'material_raw'    => $materialRaw,
            'material_cost'   => $materialRaw * (1 + $waste),
            'waste_percent'   => $category->waste_percent,
            'accessory_cost'  => $accCost,
            'cut_mm'          => $cutMm,
            'lem_cm'          => $built['lem_cm'],
            'komponen'        => $komponen,
            'accessory_count' => $built['accessory_count'],
            'cnc_potong_sec'  => $cncPotong,
            'cnc_siap_sec'    => $cncSiap,
            'cnc_base_sec'    => $cncBase,
            'asm_base_sec'    => $asmBase,
            'outer'           => $o,
            'ready_skus'      => $recipe->readySkus($input),
            'weight_kg'       => $weightG / 1000,
            'volumetric_kg'   => ($o['p'] + self::VOLUMETRIC_ADD['p']) * ($o['l'] + self::VOLUMETRIC_ADD['l'])
                                 * ($o['t'] + self::VOLUMETRIC_ADD['t']) / self::VOLUMETRIC_DIVISOR,
            'warnings'        => array_values($warnings),
        ];
    }

    private function fitsSheet(float $p, float $l, array $m): bool
    {
        if ($m['sheet_p'] <= 0 || $m['sheet_l'] <= 0) {
            return true;
        }

        return ($p <= $m['sheet_p'] && $l <= $m['sheet_l']) || ($p <= $m['sheet_l'] && $l <= $m['sheet_p']);
    }

    /** @return array<string,?int> role => product_id; 'utama' selalu ada (boleh null). */
    private function normalizeMaterialChoice(array $raw, Recipe $recipe, Collection $materials): array
    {
        $pick = fn ($v) => ($v !== null && $v !== '' && isset($materials[(int) $v])) ? (int) $v : null;

        $out = ['utama' => $pick($raw['utama'] ?? null)];
        foreach (array_keys($recipe->materialRoles()) as $role) {
            $out[$role] = $pick($raw[$role] ?? null);
        }

        return $out;
    }

    // ── Hitung harga ─────────────────────────────────────────────────────────────

    /**
     * @param  array  $raw  input mentah form (termasuk 'bahan' => [role => product_id])
     */
    public function calculate(CustomPriceCategory $category, array $raw, int $qty, float $packingKhusus = 0.0, string $packing = 'instant'): array
    {
        $qty = max(1, $qty);
        $geo = $this->geometry($category, $raw);
        if (!$geo['ok']) {
            return $geo;
        }

        $params  = $this->params($category);
        $packing = $packing === 'kayu' ? 'kayu' : 'instant';
        $kayu    = $this->packingKayu($geo['outer'], $params);
        $packingKayu = $packing === 'kayu' ? $kayu['total'] : 0.0;

        $factors = $this->factors($category);
        $basis   = $this->basis();

        $cncSec = $geo['cnc_base_sec'] * $factors['cnc']['effective'];
        $asmSec = $geo['asm_base_sec'] * $factors['assembly']['effective'];
        $rate   = $basis['rate'];

        $timeCost = $rate !== null ? ($cncSec + $asmSec) / 3600 * $rate : 0.0;

        $warnings = $geo['warnings'];
        if ($rate === null) {
            $warnings[] = 'Tarif per slot-jam belum bisa dihitung — kapasitas di Kuota Produksi masih nol. Biaya waktu terhitung Rp0.';
        }

        $capacity = $this->capacity($category, $cncSec, $asmSec);
        $warnings = array_merge($warnings, $capacity['warnings']);
        $minOrder = $category->min_order_enabled ? $capacity['min_order'] : null;

        // Biaya yang sama berapa pun jumlah pesanannya.
        $perUnitFixed = $geo['material_cost'] + $geo['accessory_cost'] + $timeCost
            + $basis['packing'] + $packingKayu + $packingKhusus;

        $channels = $this->channelFees($params);
        $markup   = 1 + $category->markup_percent / 100;

        $tier = function (int $q) use ($perUnitFixed, $category, $minOrder, $channels, $markup) {
            $setup = $category->setup_cost / $q;
            $hpp   = $perUnitFixed + $setup;
            $dasar = $hpp * $markup;
            $price = $this->roundPrice($dasar);

            // Harga MP: untung bersih sama dengan Non-MP setelah potongan kanal + tambahan custom.
            $mp = [];
            foreach ($channels as $key => $ch) {
                $keep  = 1 - $ch['percent'] / 100;
                $harga = $keep > 0 ? $this->roundPrice(($dasar + $ch['fixed']) / $keep) : null;
                $mp[$key] = [
                    'label'   => $ch['label'],
                    'price'   => $harga,
                    'percent' => $ch['percent'],
                    'fixed'   => $ch['fixed'],
                    'profit'  => $harga !== null ? $harga * $keep - $ch['fixed'] - $hpp : null,
                ];
            }

            return [
                'qty'            => $q,
                'setup_per_unit' => $setup,
                'hpp'            => $hpp,
                'price'          => $price,
                'total'          => $price * $q,
                'profit_total'   => ($price - $hpp) * $q,
                'channels'       => $mp,
                'below_min'      => $minOrder !== null && $q < $minOrder,
            ];
        };

        $qtys = array_merge(self::TIER_QTYS, [$qty], $minOrder ? [$minOrder, $minOrder * 2] : []);
        $qtys = array_values(array_unique(array_filter($qtys, fn ($q) => $q >= 1)));
        sort($qtys);

        $tiers   = array_map($tier, $qtys);
        $current = $tier($qty);
        $grosir  = $this->grosir($tier, $qty, $params);

        if ($minOrder !== null && $qty < $minOrder) {
            $warnings[] = "Pesanan {$qty} pcs di bawah minimal order {$minOrder} pcs (setengah hari kerja seluruh produksi).";
        }

        // array_merge, bukan `+`: kunci `warnings` di $geo harus TERTIMPA daftar lengkapnya.
        return array_merge($geo, [
            'qty'            => $qty,
            'factors'        => $factors,
            'cnc_sec'        => $cncSec,
            'asm_sec'        => $asmSec,
            'rate'           => $rate,
            'cnc_cost'       => $rate !== null ? $cncSec / 3600 * $rate : 0.0,
            'asm_cost'       => $rate !== null ? $asmSec / 3600 * $rate : 0.0,
            'time_cost'      => $timeCost,
            'packing'        => $basis['packing'],
            'packing_jenis'  => $packing,
            'packing_kayu'   => $packingKayu,
            'kayu'           => $kayu,
            'packing_khusus' => $packingKhusus,
            'channels'       => $channels,
            // Pembanding: HPP produk ready berukuran sama, dengan packing Instant & tanpa setting.
            'ready'          => $this->readyComparison($geo['ready_skus'], $perUnitFixed - $packingKayu - $packingKhusus),
            'setup_cost'     => $category->setup_cost,
            'markup_percent' => $category->markup_percent,
            'capacity'       => $capacity,
            'min_order'      => $minOrder,
            'current'        => $current,
            'tiers'          => $tiers,
            'grosir'         => $grosir,
            'text'           => $this->customerText($category, $geo, $current, $raw['nama'] ?? null),
            'warnings'       => $warnings,
        ]);
    }

    /**
     * Ke atas berakhiran 900, pola harga toko (103.080 → 103.900). Ke atas, karena dibulatkan
     * ke bawah = membuang untung sedikit-sedikit.
     */
    public function roundPrice(float $v): float
    {
        if ($v <= 900) {
            return 900.0;
        }

        return ceil(round($v - 900, 2) / 1000) * 1000 + 900;
    }

    /**
     * Harga grosir terkunci per kelompok + untung pesanan ini.
     *
     * Harga kelompok diambil dari hitungan pada qty dasarnya (1, minimal order, grosir). Untung
     * pesanan memakai HPP pada qty SEBENARNYA — biaya setting dibagi qty pesanan — jadi terlihat
     * berapa yang benar-benar didapat dari harga terkunci itu.
     *
     * @param  callable  $tier  fn(int $q): array — hasil hitung pada qty tertentu
     */
    public function grosir(callable $tier, int $qty, array $params): array
    {
        $min    = max(1, (int) round($params['grosir_minimal_order']));
        $mulai  = max($min + 1, (int) round($params['grosir_mulai']));

        $groups = [];
        if ($min > 1) {
            $groups[] = ['key' => 'satuan', 'label' => $min > 2 ? '1–' . ($min - 1) . ' pcs' : '1 pcs', 'from' => 1, 'to' => $min - 1, 'basis' => 1];
        }
        $groups[] = ['key' => 'normal', 'label' => $min === $mulai - 1 ? "{$min} pcs" : "{$min}–" . ($mulai - 1) . ' pcs', 'from' => $min, 'to' => $mulai - 1, 'basis' => $min];
        $groups[] = ['key' => 'grosir', 'label' => "{$mulai} pcs ke atas", 'from' => $mulai, 'to' => null, 'basis' => $mulai];

        foreach ($groups as &$g) {
            $t = $tier($g['basis']);
            $g['price']    = $t['price'];
            $g['channels'] = array_map(fn ($c) => ['label' => $c['label'], 'price' => $c['price'], 'percent' => $c['percent'], 'fixed' => $c['fixed']], $t['channels']);
            $g['applies']  = $qty >= $g['from'] && ($g['to'] === null || $qty <= $g['to']);
        }
        unset($g);

        $berlaku = collect($groups)->firstWhere('applies', true);
        $hpp     = $tier($qty)['hpp'];   // HPP per pcs pada qty pesanan sebenarnya

        $hitung = function (?float $harga, float $keep = 1.0, float $fixed = 0.0) use ($qty, $hpp) {
            if ($harga === null) {
                return null;
            }
            $bersih = $harga * $keep - $fixed;       // yang diterima per pcs setelah potongan
            $untung = ($bersih - $hpp) * $qty;

            return [
                'price'      => $harga,
                'total'      => $harga * $qty,
                'diterima'   => $bersih * $qty,
                'hpp_total'  => $hpp * $qty,
                'untung'     => $untung,
                'untung_pct' => $harga > 0 ? $untung / ($harga * $qty) * 100 : null,
            ];
        };

        $pesanan = ['nonmp' => ['label' => 'Non-MP'] + $hitung($berlaku['price'])];
        foreach ($berlaku['channels'] as $key => $c) {
            $pesanan[$key] = ['label' => $c['label']] + ($hitung($c['price'], 1 - $c['percent'] / 100, $c['fixed']) ?? []);
        }

        return [
            'minimal_order' => $min,
            'mulai'         => $mulai,
            'groups'        => $groups,
            'berlaku'       => $berlaku['label'],
            'hpp'           => $hpp,
            'pesanan'       => $pesanan,
        ];
    }

    /**
     * Peti kayu: sisi pendek + 2, sisi panjang + 4, tebal + 2 (sheet pemilik).
     * Atas-bawah = 2 × pendek × panjang; samping = 2 × (pendek + panjang) × tebal.
     */
    public function packingKayu(array $outer, array $params): array
    {
        $pendek  = min($outer['p'], $outer['l']) + $params['kemasan_tambah_pendek'];
        $panjang = max($outer['p'], $outer['l']) + $params['kemasan_tambah_panjang'];
        $tebal   = $outer['t'] + $params['kemasan_tambah_tebal'];

        $luasAB  = 2 * $pendek * $panjang;
        $luasSmp = 2 * ($pendek + $panjang) * $tebal;
        $ab      = $luasAB * $params['kayu_rp_atas_bawah'];
        $smp     = $luasSmp * $params['kayu_rp_samping'];

        return [
            'pendek'        => $pendek,
            'panjang'       => $panjang,
            'tebal'         => $tebal,
            'luas_ab'       => $luasAB,
            'luas_samping'  => $luasSmp,
            'biaya_ab'      => $ab,
            'biaya_samping' => $smp,
            'proses'        => (float) $params['kayu_biaya_proses'],
            'total'         => $ab + $smp + $params['kayu_biaya_proses'],
        ];
    }

    /**
     * Potongan per kanal marketplace = potongan di menu Harga Produk + tambahan custom.
     *
     * @return array<string,array{label:string,percent:float,fixed:float,base_percent:float,extra:float}>
     */
    public function channelFees(array $params): array
    {
        $out = [];
        foreach ($this->channelPricing->channels() as $key => $ch) {
            if ($ch['kind'] !== 'marketplace') {
                continue;
            }
            $base  = (float) $ch['fee']['percent'];
            $extra = (float) ($params['potongan_tambahan_' . $key] ?? 0);
            $out[$key] = [
                'label'        => $ch['label'],
                'base_percent' => $base,
                'extra'        => $extra,
                'percent'      => $base + $extra,
                'fixed'        => (float) $ch['fee']['fixed'],
            ];
        }

        return $out;
    }

    /** HPP & harga produk ready yang SKU-nya cocok, untuk cek silang. Null kalau tak ada. */
    public function readyComparison(array $skus, float $calcHpp): ?array
    {
        if (!$skus) {
            return null;
        }
        $found   = DB::table('products')->whereIn('sku', $skus)->get(['id', 'sku', 'name', 'base_price'])->keyBy('sku');
        $product = collect($skus)->map(fn ($s) => $found->get($s))->filter()->first();
        if (!$product) {
            return null;
        }

        $hpp = $this->productHpp->forProduct((int) $product->id, ChannelPricingService::defaultHppFilters());
        $readyHpp = isset($hpp['hpp_per_unit']) ? (float) $hpp['hpp_per_unit'] : null;

        return [
            'sku'        => $product->sku,
            'name'       => $product->name,
            'base_price' => (float) $product->base_price,
            'hpp'        => $readyHpp,
            'calc_hpp'   => $calcHpp,
            'diff_pct'   => $readyHpp ? ($calcHpp - $readyHpp) / $readyHpp * 100 : null,
        ];
    }

    private function customerText(CustomPriceCategory $category, array $geo, array $tier, ?string $nama): string
    {
        $rp    = fn (float $v) => 'Rp' . number_format($v, 0, ',', '.');
        $bahan = collect($geo['panels'])->pluck('material_name')->unique()->implode(' + ');
        $judul = trim((string) $nama) !== '' ? trim($nama) : $category->name . ' Akrilik';

        return "{$judul} {$geo['description']}, bahan {$bahan} = {$rp($tier['price'])} @ {$tier['qty']} pcs = {$rp($tier['total'])}";
    }

    /** Tarif per slot-jam & overhead packing — angka yang sama dengan HPP Ready. */
    public function basis(): array
    {
        $cost = $this->quota->build()['cost'] ?? [];

        return [
            'rate'    => isset($cost['rate_per_slot_hour']) ? (float) $cost['rate_per_slot_hour'] : null,
            'packing' => (float) ($cost['packing_per_transaction'] ?? 0),
        ];
    }

    /**
     * Kapasitas per hari kalau seluruh produksi mengerjakan produk ini — cara yang sama dengan
     * produk Ready: tiap divisi dihitung sendiri, yang paling sedikit menentukan.
     */
    public function capacity(CustomPriceCategory $category, float $cncSec, float $asmSec): array
    {
        $perDept  = $this->quota->capacityPerDay();
        $warnings = [];
        $options  = [];

        foreach ([
            'CNC'        => [$category->cnc_department_id, $cncSec],
            'Assembling' => [$category->assembly_department_id, $asmSec],
        ] as $label => [$deptId, $sec]) {
            if (!$deptId) {
                $warnings[] = "Divisi {$label} belum dipilih di Pengaturan — minimal order belum bisa dihitung.";
                continue;
            }
            $spd = $perDept[$deptId]['seconds_per_day'] ?? 0;
            if ($spd <= 0 || $sec <= 0) {
                continue;
            }
            $options[$label] = $spd / $sec;
        }

        if (!$options) {
            return ['units_per_day' => null, 'bottleneck' => null, 'min_order' => null, 'per_division' => [], 'warnings' => $warnings];
        }

        $bottleneck = array_keys($options, min($options))[0];
        $perDay     = $options[$bottleneck];

        return [
            'units_per_day' => $perDay,
            'bottleneck'    => $bottleneck,
            'min_order'     => max(1, (int) ceil($perDay * self::MIN_ORDER_DAY_FRACTION)),
            'per_division'  => $options,
            'warnings'      => $warnings,
        ];
    }

    // ── Faktor waktu dari sampel ──────────────────────────────────────────────────

    /**
     * @return array{
     *   cnc: array{data:?float, manual:?float, effective:float, n:int},
     *   assembly: array{data:?float, manual:?float, effective:float, n:int},
     *   samples: array<int,array>
     * }
     */
    public function factors(CustomPriceCategory $category): array
    {
        $samples   = $this->sampleRows($category);
        $included  = array_filter($samples, fn ($s) => !$s['excluded'] && $s['ok']);

        $calc = function (string $key) use ($included) {
            $measured = 0.0;
            $predicted = 0.0;
            $n = 0;
            foreach ($included as $s) {
                if (($s['measured_' . $key] ?? 0) <= 0) {
                    continue;   // divisi tak tercatat = tak tersampel, bukan 0 detik
                }
                $measured  += $s['measured_' . $key] * $s['units'];
                $predicted += $s['predicted_' . $key] * $s['units'];
                $n++;
            }

            return ['data' => $predicted > 0 ? $measured / $predicted : null, 'n' => $n];
        };

        $out = ['samples' => $samples];
        foreach (['cnc' => 'cnc_factor_manual', 'assembly' => 'assembly_factor_manual'] as $key => $col) {
            $c      = $calc($key);
            $manual = $category->{$col};
            $out[$key] = [
                'data'      => $c['data'],
                'manual'    => $manual,
                'effective' => $manual ?: ($c['data'] ?: 1.0),
                'source'    => $manual ? 'manual' : ($c['data'] ? 'data' : 'bawaan'),
                'n'         => $c['n'],
            ];
        }

        return $out;
    }

    /** Satu baris per sampel: perkiraan rumus (faktor 1) vs terukur dari timer, per unit. */
    public function sampleRows(CustomPriceCategory $category): array
    {
        $samples = CustomPriceSample::where('category_id', $category->id)->orderByDesc('id')->get();
        if ($samples->isEmpty()) {
            return [];
        }

        $materials = $this->materials();
        $measured  = $this->measuredSeconds($samples->pluck('production_order_id')->all());

        return $samples->map(function (CustomPriceSample $s) use ($category, $materials, $measured) {
            $geo   = $this->geometry($category, $s->input ?? [], $materials);
            $m     = $measured[$s->production_order_id] ?? null;
            $units = max($s->units, 0.0001);

            $mCnc = $m ? ($m['sec'][$category->cnc_department_id] ?? 0) / $units : 0;
            $mAsm = $m ? ($m['sec'][$category->assembly_department_id] ?? 0) / $units : 0;

            return [
                'id'                 => $s->id,
                'order_id'           => $s->production_order_id,
                'order_number'       => $m['order_number'] ?? ('#' . $s->production_order_id),
                'product_name'       => $m['product_name'] ?? null,
                'production_date'    => $m['production_date'] ?? null,
                'unfinished'         => $m['unfinished'] ?? false,
                'units'              => $s->units,
                'input'              => $s->input,
                'ok'                 => $geo['ok'] && $m !== null,
                'errors'             => $geo['errors'] ?? [],
                'description'        => $geo['description'] ?? null,
                'predicted_cnc'      => $geo['cnc_base_sec'] ?? null,
                'predicted_assembly' => $geo['asm_base_sec'] ?? null,
                'measured_cnc'       => $mCnc,
                'measured_assembly'  => $mAsm,
                'ratio_cnc'          => ($geo['ok'] && $mCnc > 0) ? $mCnc / $geo['cnc_base_sec'] : null,
                'ratio_assembly'     => ($geo['ok'] && $mAsm > 0) ? $mAsm / $geo['asm_base_sec'] : null,
                'excluded'           => $s->excluded,
                'exclude_reason'     => $s->exclude_reason,
                'notes'              => $s->notes,
            ];
        })->all();
    }

    /**
     * Detik kerja per divisi dari timer OP — hanya langkah yang sudah selesai, sama seperti
     * Waktu Produksi (langkah berjalan menambahkan now() dan akan menggelembungkan angka).
     *
     * @return array<int,array{order_number:string, product_name:?string, production_date:?string, sec:array<int,float>, unfinished:bool}>
     */
    public function measuredSeconds(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }

        $orders = ProductionOrder::whereIn('id', $orderIds)
            ->with([
                'steps.timeLogs',
                'outputs' => fn ($q) => $q->where('output_type', 'main'),
                'outputs.product:id,name',
            ])
            ->get();

        $out = [];
        foreach ($orders as $o) {
            $done = $o->steps->where('status', 'completed');
            $sec  = [];
            foreach ($done as $step) {
                $d = (int) $step->department_id;
                $sec[$d] = ($sec[$d] ?? 0) + (float) $step->elapsed_working_seconds;
            }

            $out[$o->id] = [
                'order_number'    => $o->order_number,
                'product_name'    => $o->outputs->first()?->product?->name,
                'production_date' => optional($o->production_date)->format('Y-m-d'),
                'sec'             => $sec,
                'unfinished'      => $done->count() !== $o->steps->count(),
            ];
        }

        return $out;
    }

    /** Jumlah unit bawaan sebuah OP: total rencana output utama. */
    public function orderUnits(ProductionOrder $order): float
    {
        return (float) $order->outputs()->where('output_type', 'main')->sum('qty_planned');
    }
}
