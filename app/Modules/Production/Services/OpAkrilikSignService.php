<?php

namespace App\Modules\Production\Services;

use App\Core\Inventory\Product;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Tasks\MejaKerja\BebanAdmin;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OP stok Akrilik Sign (SKU AS-*): BAS polos + stiker → dicetak & ditempel. Disepakati owner
 * 21 Nov 2026. BOM-nya: hasil utama AS-*, auto-produksi MATI (skill ini yang mengurus), punya
 * langkah Desain, "Jumlah Siklus Biasa" = jumlah sekali produksi (diisi owner per SKU).
 *
 * Alat tempel cuma satu (dipakai bergantian Admin Desain & Assembling) → admin memegang paling
 * banyak SATU langkah tempel terbuka.
 *
 *   Stok ≤ min, belum ada OP  + anggota meja Desain LONGGAR + tak ada langkah tempel admin terbuka
 *        → OP langkah "Desain — cetak & tempel"
 *   Stok ≤ min, selain itu    → OP "Desain — cetak" lalu Assembling menempel
 *   Tak ada yang menipis + anggota Desain NGANGGUR + alat kosong + belum ada OP pengisi
 *        → 1 OP pengisi (Prioritas Rendah, jalur tempel) untuk SKU terlaris yang stoknya
 *          < min + jumlah sekali produksi (stok cepat habis dulu)
 *   OP pengisi yang SKU-nya keburu menipis → dinaikkan ke prioritas biasa.
 */
class OpAkrilikSignService
{
    public const SKILL  = 'produksi.op-akrilik-sign';
    public const PREFIX = 'AS-';
    public const HARI_LARIS = 30;

    public function __construct(
        private AutoProductionService $auto,
        private BomService $bomService,
        private ProductionOrderService $orderService,
        private AlatTempelService $alat,
        private BebanAdmin $beban,
    ) {}

    /**
     * @return list<array{sku: ?string, created: bool, simulasi: bool, jenis: string, order_number: ?string, reason: string}>
     */
    public function periksa(bool $simulasi = false, ?string $hanyaSku = null): array
    {
        $this->alat->lupakan();
        $kandidat = $this->kandidat();
        if ($hanyaSku !== null) $kandidat = $kandidat->where('sku', $hanyaSku);
        if ($kandidat->isEmpty()) {
            return [$this->baris(null, 'dilewati', 'Belum ada BOM Akrilik Sign (hasil utama AS-*, auto-produksi mati, punya langkah Desain).')];
        }

        $hasil = [];
        $desain = $this->bebanDesain();
        $adaLonggar = collect($desain)->contains(fn ($k) => $this->beban->longgar($k));
        $ringkasBeban = $desain ? collect($desain)->map(fn ($k) => $this->beban->ringkas($k))->implode('; ') : 'meja Desain belum punya anggota';
        $tempelTerbuka = $this->alat->tempelAdminTerbuka();

        // ── Stok menipis ──
        $menipis = $kandidat->filter(fn ($k) => $k['min'] !== null && $k['stok'] <= $k['min'])
            ->sortBy(fn ($k) => $k['min'] > 0 ? $k['stok'] / $k['min'] : $k['stok'])->values();
        foreach ($menipis as $k) {
            $pemicu = "stok {$this->fmt($k['stok'])} ≤ min {$this->fmt($k['min'])}";
            if ($k['berjalan']) {
                $hasil[] = $this->naikkanPengisi($k, $pemicu, $simulasi);
                continue;
            }
            if ($tempelTerbuka === null && $adaLonggar) {
                $jalur = 'tempel';
                $alasan = "Admin Desain longgar ($ringkasBeban).";
                $tempelTerbuka = '(OP ini)';
            } else {
                $jalur = 'cetak';
                $alasan = $tempelTerbuka !== null
                    ? "Admin masih memegang langkah tempel ($tempelTerbuka) — alat tempel cuma satu, Assembling yang menempel."
                    : "Admin Desain sibuk ($ringkasBeban) — Admin cetak saja, Assembling yang menempel.";
            }
            $hasil[] = $this->buat($k, $jalur, false, "$pemicu. $alasan", $simulasi);
        }

        // ── OP pengisi: admin nganggur & stok laris belum penuh ──
        if ($hanyaSku === null && $menipis->where('berjalan', null)->isEmpty()) {
            $hasil[] = $this->pengisi($kandidat, $desain, $simulasi);
        }

        return array_values(array_filter($hasil));
    }

    /** @return Collection<int, array> per SKU: product, bom, siklus, stok, min, berjalan (OP aktif) */
    public function kandidat(): Collection
    {
        $bom = Bom::with(['outputs.product', 'steps', 'materials'])
            ->where('auto_production', false)
            ->whereHas('outputs', fn ($q) => $q->where('output_type', 'main')
                ->whereHas('product', fn ($p) => $p->where('sku', 'like', self::PREFIX . '%')->where('is_active', true)))
            ->whereHas('steps', fn ($q) => $q->whereNotNull('desain'))
            ->orderByRaw('COALESCE(urutan_pakai, 999)')->orderBy('id')
            ->get();

        return $bom->map(function (Bom $b) {
            $p = $b->outputs->firstWhere('output_type', 'main')->product;
            return ['product' => $p, 'bom' => $b];
        })->unique(fn ($x) => $x['product']->id)->map(function ($x) {
            $p = $x['product'];
            $berjalan = ProductionOrder::whereNotIn('status', ['finalized', 'cancelled', 'merged'])
                ->whereNotIn('type', ProductionOrder::REPAIR_TYPES)
                ->whereHas('outputs', fn ($q) => $q->where('product_id', $p->id)->where('qty_planned', '>', 0))
                ->orderBy('id')->first();
            return [
                'sku'      => $p->sku,
                'product'  => $p,
                'bom'      => $x['bom'],
                'siklus'   => max(1, (int) ($x['bom']->typical_cycles ?? 1)),
                'stok'     => $this->auto->stokTersedia($p),
                'min'      => $p->min_stock !== null ? (float) $p->min_stock : null,
                'berjalan' => $berjalan,
            ];
        })->values();
    }

    /** @return list<array> keadaan tiap anggota meja Desain */
    private function bebanDesain(): array
    {
        return MejaKerjaPeran::where('peran', 'desain')->orderBy('urutan')->pluck('user_id')
            ->map(fn ($id) => $this->beban->keadaan((int) $id))->all();
    }

    private function pengisi(Collection $kandidat, array $desain, bool $simulasi): ?array
    {
        $aktif = ProductionOrder::where('kv_skill', self::SKILL)->where('priority_level', 'low')
            ->whereNotIn('status', ['finalized', 'cancelled', 'merged'])->value('order_number');
        if ($aktif) return null;

        $nganggur = collect($desain)->first(fn ($k) => $this->beban->nganggur($k));
        if (!$nganggur) return null;                       // tak ada yang nganggur: diam (bukan berita)
        if ($this->alat->tempelAdminTerbuka() || $this->alat->dipakai()) {
            return null;                                    // alat sedang/akan dipakai pekerjaan lain
        }

        $terjual = $this->terjual($kandidat->pluck('product.id')->all());
        $pilih = $kandidat->filter(fn ($k) => !$k['berjalan'] && ($terjual[$k['product']->id] ?? 0) > 0
                && $k['stok'] < ($k['min'] ?? 0) + $k['siklus'] * $this->hasilPerSiklus($k['bom']))
            ->sortBy(fn ($k) => $k['stok'] / max(0.01, $terjual[$k['product']->id] / self::HARI_LARIS))
            ->first();
        if (!$pilih) {
            return $this->baris(null, 'dilewati', "{$nganggur['nama']} nganggur, tapi stok semua Akrilik Sign yang laku "
                . self::HARI_LARIS . ' hari terakhir sudah ≥ min + sekali produksi.');
        }

        $laku = $this->fmt($terjual[$pilih['product']->id]);
        $hari = round($pilih['stok'] / max(0.01, $terjual[$pilih['product']->id] / self::HARI_LARIS));
        return $this->buat($pilih, 'tempel', true,
            "Pengisi: {$this->beban->ringkas($nganggur)}. Terjual $laku dalam " . self::HARI_LARIS . " hari, stok {$this->fmt($pilih['stok'])} (±$hari hari).",
            $simulasi);
    }

    /** OP pengisi (Prioritas Rendah) yang SKU-nya kini menipis → prioritas biasa. */
    private function naikkanPengisi(array $k, string $pemicu, bool $simulasi): ?array
    {
        $op = $k['berjalan'];
        if ($op->kv_skill !== self::SKILL || $op->priority_level !== 'low') return null; // OP biasa sudah ada: diam
        if (!$simulasi) $op->update(['score_type' => 'auto', 'priority_level' => null]);

        return $this->baris($k['sku'], 'diubah', "OP pengisi {$op->order_number} dinaikkan ke prioritas biasa — $pemicu.", $simulasi, $op->order_number);
    }

    private function buat(array $k, string $jalur, bool $pengisi, string $alasan, bool $simulasi): array
    {
        $bom = $k['bom'];
        $langkah = $this->langkah($bom, $jalur);
        if (is_string($langkah)) return $this->baris($k['sku'], 'dilewati', $langkah);

        $qty = $k['siklus'] * $this->hasilPerSiklus($bom);
        $label = $jalur === 'tempel' ? 'cetak & tempel oleh Admin Desain' : 'Admin cetak → Assembling tempel';
        $sisa = $this->sisaLembar($bom, $k['siklus']);
        $ringkas = "{$k['sku']} {$this->fmt($qty)} pcs ($label)" . ($sisa ? " ⚠ $sisa" : '') . ". $alasan";

        if ($simulasi) return $this->baris($k['sku'], 'akan', "Akan membuat OP $ringkas", true);

        $gudang = $this->auto->gudangProduksi();
        if (!$gudang) return $this->baris($k['sku'], 'gagal', 'Gagal: tidak ada gudang aktif.');

        try {
            $order = DB::transaction(fn () => $this->orderService->create([
                'type'            => 'ready_stock',
                'bom_id'          => $bom->id,
                'warehouse_id'    => $gudang->id,
                'production_date' => now()->format('Y-m-d'),
                'planned_cycles'  => $k['siklus'],
                'score_type'      => $pengisi ? 'priority' : 'auto',
                'priority_level'  => $pengisi ? 'low' : null,
                'created_via'     => 'auto',
                'kv_skill'        => self::SKILL,
                'description'     => $bom->description,
                'notes'           => "OP Akrilik Sign oleh Manager Produksi: $ringkas",
                'materials'       => array_map(fn ($m) => ['product_id' => $m['product_id'], 'qty_required' => $m['qty_required'], 'unit' => $m['unit'] ?? null],
                    $this->bomService->calculateMaterials($bom->id, $k['siklus'])),
                'outputs'         => array_map(fn ($o) => ['product_id' => $o['product_id'], 'qty_planned' => $o['qty_planned'], 'output_type' => $o['output_type'], 'percentage' => $o['percentage']],
                    $this->bomService->calculateOutputs($bom->id, $k['siklus'])),
                'steps'           => $langkah,
            ]));

            try {
                $this->orderService->confirm($order->id);
                $status = 'dikonfirmasi';
            } catch (\Throwable $e) {
                $status = 'tetap draft (konfirmasi gagal: ' . $e->getMessage() . ')';
            }
            $this->alat->lupakan();

            return $this->baris($k['sku'], 'dibuat', "OP {$order->order_number} dibuat — $ringkas ($status)", false, $order->order_number);
        } catch (\Throwable $e) {
            Log::error('OpAkrilikSignService gagal membuat OP', ['sku' => $k['sku'], 'message' => $e->getMessage()]);
            return $this->baris($k['sku'], 'gagal', 'Gagal membuat OP: ' . $e->getMessage());
        }
    }

    /**
     * Langkah OP dari BOM. Jalur cetak: tiap "Desain — cetak & tempel" diganti "Desain — cetak"
     * lalu Assembling (tempel). String = alasan dilewati.
     *
     * @return list<array>|string
     */
    public function langkah(Bom $bom, string $jalur): array|string
    {
        $steps = $this->bomService->getSteps($bom->id);
        if (!collect($steps)->contains(fn ($s) => str_starts_with((string) $s['department_id'], 'desain:'))) {
            return "BOM {$bom->bom_number} tidak punya langkah Desain.";
        }
        if ($jalur === 'tempel') return $steps;

        $asm = Department::where('type', 'produksi')->where('is_active', true)->where('name', 'like', 'Assembling%')->first();
        if (!$asm) return 'Divisi Assembling (aktif) tidak ditemukan untuk menempel.';

        $hasil = [];
        foreach ($steps as $s) {
            if ($s['department_id'] === 'desain:tempel') {
                $hasil[] = ['name' => '', 'department_id' => 'desain:cetak'];
                $hasil[] = ['name' => $asm->name, 'department_id' => $asm->id];
            } else {
                $hasil[] = $s;
            }
        }
        return $hasil;
    }

    /** "stiker sisa 2 dari 4 slot lembar" bila jumlah produksi bukan kelipatan isi lembar. */
    public function sisaLembar(Bom $bom, int $siklus): ?string
    {
        foreach ($bom->materials as $m) {
            $per = (float) $m->qty_per_cycle;
            if ($per <= 0 || $per >= 1) continue;               // hanya bahan pecahan (lembar stiker)
            $isi = (int) round(1 / $per);
            $sisa = ($isi - $siklus % $isi) % $isi;
            if ($sisa > 0) return "lembar {$m->product?->name} sisa $sisa dari $isi slot (jumlah bukan kelipatan $isi)";
        }
        return null;
    }

    private function hasilPerSiklus(Bom $bom): float
    {
        return (float) ($bom->outputs->firstWhere('output_type', 'main')?->qty_per_cycle ?? 1);
    }

    /** @return array<int, float> product_id → qty terjual HARI_LARIS hari terakhir (SO confirmed) */
    private function terjual(array $productIds): array
    {
        return SalesOrderItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.status', 'confirmed')
            ->whereDate('sales_orders.order_date', '>=', now()->subDays(self::HARI_LARIS)->toDateString())
            ->whereIn('sales_order_items.product_id', $productIds)
            ->groupBy('sales_order_items.product_id')
            ->selectRaw('sales_order_items.product_id, SUM(sales_order_items.qty) n')
            ->pluck('n', 'product_id')->map(fn ($n) => (float) $n)->all();
    }

    private function baris(?string $sku, string $jenis, string $reason, bool $simulasi = false, ?string $nomor = null): array
    {
        return ['sku' => $sku, 'created' => $jenis === 'dibuat', 'simulasi' => $simulasi, 'jenis' => $jenis, 'order_number' => $nomor, 'reason' => $reason];
    }

    private function fmt($n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    }
}
