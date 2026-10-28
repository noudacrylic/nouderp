<?php

namespace App\Modules\Production\Services;

use App\Core\Inventory\Product;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OP koin kotak saran. Keempat koin dibuat bersama dari SATU BOM (KOIN-TPM utama,
 * PH/PK/SPH sampingan): 1 lembar akrilik besar dipotong jadi 20 lembar kecil, tiap
 * lembar kecil ditempel stiker satu jenis koin lalu di-CNC jadi 150 koin.
 *
 * Pemicu: koin MANA PUN yang tersedia ≤ min stok (bukan hanya KOIN-TPM), dan belum ada
 * OP koin berjalan.
 *
 * Pembagian lembar (disepakati user 28 Okt 2026): setelah produksi stok TPM ≈ 3× stok
 * tiap koin lain, dan PH/PK/SPH hampir sama. Koin yang stoknya sudah melebihi bagiannya
 * tidak dapat lembar (water-filling), sisa pecahan lembar diberikan ke koin yang paling
 * kurang. Satu lembar besar per OP; ditambah lembar besar selama masih ada koin yang
 * setelah produksi tetap ≤ min stok.
 */
class OpKoinService
{
    public const SKILL = 'produksi.op-koin';

    /** SKU koin => bobot stok sesudah produksi. Koin utama BOM = KOIN-TPM. */
    public const BOBOT = ['KOIN-TPM' => 3, 'KOIN-PH' => 1, 'KOIN-PK' => 1, 'KOIN-SPH' => 1];
    public const UTAMA = 'KOIN-TPM';

    public const LEMBAR_PER_SIKLUS = 20;   // 1 lembar besar → 20 lembar kecil
    public const KOIN_PER_LEMBAR   = 150;  // 1 lembar kecil → 150 koin
    public const MAKS_SIKLUS       = 5;

    public function __construct(
        private AutoProductionService $auto,
        private BomService $bomService,
        private ProductionOrderService $orderService,
    ) {}

    /**
     * Satu pemeriksaan. Hasil SATU baris (dipakai jurnal & usulan).
     *
     * @return array{created: bool, simulasi: bool, order_number: ?string, siklus: int, lembar: array, reason: string}
     */
    public function periksa(bool $simulasi = false): array
    {
        $base = ['created' => false, 'simulasi' => false, 'order_number' => null, 'siklus' => 0, 'lembar' => [], 'stok' => []];

        $koin = Product::whereIn('sku', array_keys(self::BOBOT))->get()->keyBy('sku');
        if ($koin->count() !== count(self::BOBOT)) {
            return $base + ['reason' => 'Produk koin belum lengkap: ' . implode(', ', array_diff(array_keys(self::BOBOT), $koin->keys()->all())) . '.'];
        }

        $bom = $this->bom($koin[self::UTAMA]);
        if (!$bom) {
            return $base + ['reason' => 'BOM koin (KOIN-TPM sebagai hasil utama) tidak ditemukan.'];
        }
        if ($bom->auto_production) {
            return $base + ['reason' => "BOM {$bom->bom_number} auto-produksi aktif — diurus skill stok menipis, bukan skill ini."];
        }

        $stok = $koin->map(fn (Product $p) => $this->auto->stokTersedia($p))->all();
        $base['stok'] = $stok;

        $menipis = $koin->filter(fn (Product $p) => $p->min_stock !== null && $stok[$p->sku] <= (float) $p->min_stock)
            ->map(fn (Product $p) => "{$p->sku} {$this->fmt($stok[$p->sku])} ≤ min {$this->fmt($p->min_stock)}");
        if ($menipis->isEmpty()) {
            return $base + ['reason' => 'Stok semua koin masih aman: ' . $this->ringkasStok($stok) . '.'];
        }

        $berjalan = $this->opBerjalan($koin);
        if ($berjalan) {
            return $base + ['reason' => "Masih ada OP koin berjalan ({$berjalan}) — " . $menipis->implode(', ') . '.'];
        }

        [$siklus, $lembar] = $this->rencana($stok, $koin->map(fn ($p) => (float) $p->min_stock)->all());
        $base['siklus'] = $siklus;
        $base['lembar'] = $lembar;
        $pemicu  = $menipis->implode(', ');
        $rincian = $this->ringkasLembar($lembar, $stok);

        if ($simulasi) {
            return array_merge($base, ['simulasi' => true,
                'reason' => "Akan membuat OP koin {$siklus} lembar besar ({$pemicu}): {$rincian}."]);
        }

        $gudang = $this->auto->gudangProduksi();
        if (!$gudang) {
            return $base + ['reason' => 'Tidak ada gudang aktif untuk dipakai.'];
        }

        try {
            $order = DB::transaction(fn () => $this->buatOp($bom, $koin, $siklus, $lembar, $gudang->id, $pemicu, $rincian));

            try {
                $this->orderService->confirm($order->id);
                $status = 'dikonfirmasi, siap dikerjakan';
            } catch (\Throwable $e) {
                $status = 'tetap draft (konfirmasi gagal: ' . $e->getMessage() . ')';
            }

            return array_merge($base, ['created' => true, 'order_number' => $order->order_number,
                'reason' => "OP koin {$siklus} lembar besar dibuat ({$pemicu}): {$rincian} — {$status}."]);
        } catch (\Throwable $e) {
            Log::error('OpKoinService gagal membuat OP', ['message' => $e->getMessage()]);
            return $base + ['reason' => 'Gagal membuat OP koin: ' . $e->getMessage()];
        }
    }

    /**
     * Jumlah lembar besar + lembar kecil per SKU. Mulai 1 lembar besar; tambah selama masih
     * ada koin yang sesudah produksi tetap ≤ min stok.
     *
     * @param  array<string, float> $stok
     * @param  array<string, float> $min
     * @return array{0: int, 1: array<string, int>}
     */
    public function rencana(array $stok, array $min): array
    {
        for ($siklus = 1; ; $siklus++) {
            $lembar = $this->bagiLembar($stok, $siklus * self::LEMBAR_PER_SIKLUS);
            $masihMenipis = collect($stok)->contains(fn ($s, $sku) =>
                $s + $lembar[$sku] * self::KOIN_PER_LEMBAR <= ($min[$sku] ?? 0));
            if (!$masihMenipis || $siklus >= self::MAKS_SIKLUS) {
                return [$siklus, $lembar];
            }
        }
    }

    /**
     * Bagi $total lembar kecil supaya stok sesudah produksi sebanding BOBOT (3:1:1:1).
     *
     * @param  array<string, float> $stok
     * @return array<string, int>
     */
    public function bagiLembar(array $stok, int $total): array
    {
        $koinBaru = $total * self::KOIN_PER_LEMBAR;

        // Water-filling: koin yang stoknya sudah melebihi bagiannya dikeluarkan, lalu
        // bagian dihitung ulang di antara sisanya, sampai stabil.
        $ikut = array_keys(self::BOBOT);
        do {
            $bobot  = array_sum(array_map(fn ($sku) => self::BOBOT[$sku], $ikut));
            $satuan = (array_sum(array_map(fn ($sku) => $stok[$sku], $ikut)) + $koinBaru) / $bobot;
            $lebih  = array_filter($ikut, fn ($sku) => $stok[$sku] > self::BOBOT[$sku] * $satuan);
            $ikut   = array_values(array_diff($ikut, $lebih));
        } while ($lebih);

        $perlu = [];
        foreach (array_keys(self::BOBOT) as $sku) {
            $perlu[$sku] = in_array($sku, $ikut, true)
                ? max(0, (self::BOBOT[$sku] * $satuan - $stok[$sku]) / self::KOIN_PER_LEMBAR)
                : 0.0;
        }

        // Bulatkan ke bawah, sisa lembar ke pecahan terbesar (= koin yang paling kurang).
        $lembar = array_map(fn ($x) => (int) floor($x + 1e-9), $perlu);
        $sisa   = $total - array_sum($lembar);
        $urut   = collect($perlu)->map(fn ($x, $sku) => $x - $lembar[$sku])
            ->sortDesc()->keys()->all();
        for ($i = 0; $sisa > 0; $i++, $sisa--) {
            $lembar[$urut[$i % count($urut)]]++;
        }

        return $lembar;
    }

    private function buatOp(Bom $bom, $koin, int $siklus, array $lembar, int $gudangId, string $pemicu, string $rincian): ProductionOrder
    {
        $totalKoin = array_sum($lembar) * self::KOIN_PER_LEMBAR;

        $outputs = [];
        foreach ($lembar as $sku => $n) {
            if ($n <= 0) continue;
            $qty = $n * self::KOIN_PER_LEMBAR;
            $outputs[] = [
                'product_id'  => $koin[$sku]->id,
                'qty_planned' => $qty,
                'output_type' => $sku === self::UTAMA ? 'main' : 'by_product',
                // Biaya dibagi sebanding jumlah koin — semua koin dari bahan & proses yang sama.
                'percentage'  => round($qty / $totalKoin * 100, 2),
            ];
        }
        if (!collect($outputs)->contains('output_type', 'main')) {
            // KOIN-TPM tidak dapat lembar: tetap dicantumkan sebagai hasil utama (0) supaya OP sah.
            array_unshift($outputs, ['product_id' => $koin[self::UTAMA]->id, 'qty_planned' => 0, 'output_type' => 'main', 'percentage' => 0]);
        }

        return $this->orderService->create([
            'type'            => 'ready_stock',
            'bom_id'          => $bom->id,
            'warehouse_id'    => $gudangId,
            'production_date' => now()->format('Y-m-d'),
            'planned_cycles'  => $siklus,
            'score_type'      => 'auto',
            'created_via'     => 'auto',
            'kv_skill'        => self::SKILL,
            'description'     => $bom->description,
            'notes'           => "OP koin oleh Manager Produksi ({$pemicu}). Lembar kecil: {$rincian}.",
            'materials'       => array_map(fn ($m) => [
                'product_id'   => $m['product_id'],
                'qty_required' => $m['qty_required'],
                'unit'         => $m['unit'] ?? null,
            ], $this->bomService->calculateMaterials($bom->id, $siklus)),
            'outputs' => $outputs,
            'steps'   => $this->bomService->getSteps($bom->id),
        ]);
    }

    /** BOM yang hasil utamanya KOIN-TPM dan memuat ketiga koin lain sebagai sampingan. */
    private function bom(Product $utama): ?Bom
    {
        return Bom::with('outputs.product')
            ->whereHas('outputs', fn ($q) => $q->where('output_type', 'main')->where('product_id', $utama->id))
            ->get()
            ->first(fn (Bom $b) => $b->outputs->pluck('product.sku')->intersect(array_keys(self::BOBOT))->count() === count(self::BOBOT));
    }

    /** Nomor OP berjalan yang menghasilkan koin mana pun (utama atau sampingan), atau null. */
    private function opBerjalan($koin): ?string
    {
        return ProductionOrder::whereNotIn('status', ['finalized', 'cancelled', 'merged'])
            ->whereNotIn('type', ProductionOrder::REPAIR_TYPES)
            ->whereHas('outputs', fn ($q) => $q->whereIn('product_id', $koin->pluck('id'))->where('qty_planned', '>', 0))
            ->value('order_number');
    }

    private function ringkasLembar(array $lembar, array $stok): string
    {
        return collect($lembar)->map(fn ($n, $sku) => "{$sku} {$n} lbr (" . $this->fmt($n * self::KOIN_PER_LEMBAR)
            . ', stok ' . $this->fmt($stok[$sku]) . ' → ' . $this->fmt($stok[$sku] + $n * self::KOIN_PER_LEMBAR) . ')')
            ->implode(', ');
    }

    private function ringkasStok(array $stok): string
    {
        return collect($stok)->map(fn ($s, $sku) => "{$sku} {$this->fmt($s)}")->implode(', ');
    }

    private function fmt($n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }
}
