<?php

namespace App\Modules\Production\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Services\LajuJualService;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Isi antrean CNC yang kosong (jam kerja), satu OP sekali jalan, berjenjang:
 *
 *   Tingkat 1 — produk andalan (products.produk_andalan): OP walau stok masih banyak.
 *               Belum ada OP → skor Otomatis; sudah 1 OP → OP kedua Prioritas Rendah,
 *               naik ke Otomatis begitu OP sebelumnya selesai. Maks 2 OP berjalan.
 *   Tingkat 2 — hampir menipis: stok di atas minimum tapi cukup ≤ 10 hari penjualan.
 *   Tingkat 3 — stok cadangan: produk knockdown laris yang stok jualnya cukup ≥ 14 hari;
 *               hasil ke gudang Cadangan sampai cadangan ≈ 30 hari penjualan.
 *               Bila selagi OP berjalan stok jualnya diborong (cukup ≤ 10 hari),
 *               hasilnya dialihkan ke gudang jual dan skornya jadi Otomatis.
 *
 * Wilayah tiap tingkat tak bertumpuk: ≤ min = skill stok menipis; ≤ 10 hari = Tingkat 2;
 * 10–14 hari = jeda; ≥ 14 hari = Tingkat 3. Produk andalan hanya diurus Tingkat 1.
 * "Laku/hari" = LajuJualService (sama dengan kolom di halaman Stok).
 */
class IsiAntreanCncService
{
    public const SKILL = 'produksi.isi-antrean-cnc';

    /** Angka uji coba — disepakati boleh diubah sambil jalan. */
    public const MAKS_OP_ANDALAN       = 2;
    public const T2_MAKS_HARI          = 10;
    public const T2_MIN_LAKU_30HR      = 30;
    public const T3_MIN_HARI_JUAL      = 14;
    public const T3_CADANGAN_HARI      = 30;
    public const T3_MIN_LAKU_30HR      = 100;
    public const T3_POLA_SKU           = 'TBKD-';

    public function __construct(
        private AutoProductionService $auto,
        private BomService $bomService,
        private ProductionOrderService $orderService,
        private AntreanProduksiService $antrean,
        private LajuJualService $laju,
    ) {}

    public static function penanda(int $tingkat): string
    {
        return self::SKILL . ':' . $tingkat;
    }

    /**
     * Baris hasil: 'jenis' = naikkan | pindah (penyesuaian OP yang sudah ada),
     * antrean (keadaan antrean), tingkat (tingkat dilewati + alasan), buat (OP dibuat/akan).
     * $naikkanSkor = false → OP lama tak disentuh (mode Bayangan).
     */
    public function jalankan(bool $simulasi = false, bool $naikkanSkor = true, ?Carbon $saat = null): array
    {
        $hasil = $naikkanSkor ? $this->sesuaikanOpBerjalan() : [];
        $saat ??= now();

        if ($lewati = $this->alasanTakPerluIsi($saat)) {
            return [...$hasil, $this->baris('antrean', 0, null, ['reason' => $lewati])];
        }

        foreach ([1, 2, 3] as $t) {
            [$calon, $catatan] = $this->calon($t);
            if ($calon) {
                return [...$hasil, $this->buat($t, $calon[0], $simulasi)];
            }
            $hasil[] = $this->baris('tingkat', $t, null, ['reason' => "Tingkat {$t} dilewati: {$catatan}"]);
        }

        return $hasil;
    }

    /** Usulan disetujui: periksa ulang antrean & syarat tingkatnya untuk produk ini. */
    public function kerjakanUntuk(Product $produk, int $tingkat): array
    {
        $cnc = $this->antrean->departemen('CNC');
        if ($cnc && ($n = $this->antrean->jumlah($cnc)) > 0) {
            return $this->baris('buat', $tingkat, $produk, ['reason' => "Antrean CNC sudah berisi {$n} pekerjaan."]);
        }
        [$calon, $catatan] = $this->calon($tingkat);
        $pilih = collect($calon)->first(fn ($c) => $c['produk']->id === $produk->id);
        if (!$pilih) {
            return $this->baris('buat', $tingkat, $produk, ['reason' => "Tidak lagi memenuhi syarat Tingkat {$tingkat}. {$catatan}"]);
        }

        return $this->buat($tingkat, $pilih, false);
    }

    private function alasanTakPerluIsi(Carbon $saat): ?string
    {
        if (!$this->antrean->jamKerja($saat)) {
            return 'Di luar jam kerja (' . $saat->translatedFormat('l H:i') . ') — antrean CNC tidak diisi.';
        }
        $cnc = $this->antrean->departemen('CNC');
        if (!$cnc) {
            return 'Departemen "CNC" tidak ditemukan.';
        }
        if (($n = $this->antrean->jumlah($cnc)) > 0) {
            return "Antrean CNC masih berisi {$n} pekerjaan.";
        }
        // OP pengisi yang gagal dikonfirmasi tak tampil di antrean — tanpa rem ini tiap 30 menit
        // lahir OP baru untuk produk berikutnya.
        $draft = ProductionOrder::where('kv_skill', 'like', self::SKILL . ':%')->where('status', 'draft')->value('order_number');
        if ($draft) {
            return "OP pengisi {$draft} masih draft (belum dikonfirmasi) — selesaikan dulu.";
        }
        if (!$this->auto->gudangProduksi()) {
            return 'Tidak ada gudang aktif untuk dipakai.';
        }
        return null;
    }

    /**
     * Calon tingkat $t, terurut prioritas, beserta catatan bila kosong.
     *
     * @return array{0: array<int, array>, 1: string}
     */
    public function calon(int $t): array
    {
        $gudang  = $this->auto->gudangProduksi();
        $semua   = $this->produkCnc();
        $laju    = $this->laju->perHari(array_column($semua, 'id'));
        $calon   = [];
        $bahan   = [];
        $cadangan = $t === 3 ? $this->gudangCadangan() : null;

        if ($t === 3 && !$cadangan) {
            return [[], 'tidak ada gudang Cadangan (gudang aktif non-jual bernama "Cadangan").'];
        }

        foreach ($semua as $x) {
            /** @var Product $p */
            $p        = $x['produk'];
            $perHari  = $laju[$p->id] ?? 0;
            $tersedia = $this->auto->stokTersedia($p);
            $cukup    = LajuJualService::hariCukup($tersedia, $perHari);
            $aktif    = $this->auto->jumlahProduksiAktif($p);
            $min      = $p->min_stock !== null ? (float) $p->min_stock : null;
            $info     = compact('perHari', 'tersedia', 'cukup', 'aktif');

            $lolos = match ($t) {
                1 => $p->produk_andalan && $aktif < self::MAKS_OP_ANDALAN,
                2 => !$p->produk_andalan && $aktif === 0
                    && $tersedia > 0 && !($min > 0 && $tersedia <= $min)
                    && $perHari * LajuJualService::HARI >= self::T2_MIN_LAKU_30HR
                    && $cukup !== null && $cukup <= self::T2_MAKS_HARI,
                3 => !$p->produk_andalan && $aktif === 0
                    && str_starts_with(strtoupper($p->sku), self::T3_POLA_SKU)
                    && $perHari * LajuJualService::HARI >= self::T3_MIN_LAKU_30HR
                    && $cukup !== null && $cukup >= self::T3_MIN_HARI_JUAL
                    && ($info['cadangan'] = $this->stokDi($p, $cadangan)) < $perHari * self::T3_CADANGAN_HARI,
            };
            if (!$lolos) continue;

            [$bom, $catatanBom, $cukupBahan] = $this->auto->pilihBom($x['bom'], $gudang);
            if (!$cukupBahan) {
                $bahan[] = "{$p->sku} (" . ($catatanBom ?? $this->auto->bahanKurang($bom, $gudang)) . ')';
                continue;
            }
            $calon[] = ['bom' => $bom, 'catatan_bom' => $catatanBom] + $x + $info;
        }

        usort($calon, fn ($a, $b) => match ($t) {
            // Andalan & hampir menipis: yang paling cepat habis dulu.
            1, 2 => [$a['cukup'] ?? INF, $a['produk']->sku] <=> [$b['cukup'] ?? INF, $b['produk']->sku],
            // Cadangan: yang cadangannya paling tipis dibanding lakunya.
            3 => [$a['cadangan'] / max(1e-9, $a['perHari']), $a['produk']->sku] <=> [$b['cadangan'] / max(1e-9, $b['perHari']), $b['produk']->sku],
        });

        $catatan = match ($t) {
            1 => 'tidak ada produk andalan yang OP berjalannya kurang dari ' . self::MAKS_OP_ANDALAN . '.',
            2 => 'tidak ada produk yang stoknya cukup ≤ ' . self::T2_MAKS_HARI . ' hari (di atas minimum, laku ≥ '
                . self::T2_MIN_LAKU_30HR . '/30 hari) tanpa OP berjalan.',
            3 => 'tidak ada produk ' . self::T3_POLA_SKU . '* laku ≥ ' . self::T3_MIN_LAKU_30HR . '/30 hari dengan stok jual ≥ '
                . self::T3_MIN_HARI_JUAL . ' hari dan cadangan < ' . self::T3_CADANGAN_HARI . ' hari tanpa OP berjalan.',
        };
        if ($bahan) {
            $catatan .= ' Bahan kurang: ' . implode(', ', $bahan) . '.';
        }

        return [$calon, $catatan];
    }

    private function buat(int $t, array $c, bool $simulasi): array
    {
        /** @var Product $p */
        $p      = $c['produk'];
        /** @var Bom $bom */
        $bom    = $c['bom'];
        $siklus = max(1, (int) ($bom->typical_cycles ?? 1));
        $rendah = $t === 3 || ($t === 1 && $c['aktif'] > 0);
        $fmt    = fn ($v) => $v === null ? '∞' : rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');

        $alasan = match ($t) {
            1 => "produk andalan, OP berjalan {$c['aktif']}",
            2 => "hampir menipis: stok {$fmt($c['tersedia'])} cukup {$fmt($c['cukup'])} hari",
            3 => "stok cadangan: stok jual cukup {$fmt($c['cukup'])} hari, cadangan {$fmt($c['cadangan'])} pcs",
        } . " (laku {$fmt($c['perHari'])}/hari)"
            . (($c['catatan_bom'] ?? null) ? "; BOM: {$c['catatan_bom']}" : '');
        $skor = $rendah ? 'Prioritas Rendah' : 'Otomatis';
        $ringkas = "Tingkat {$t} — {$siklus} siklus {$bom->bom_number}, {$alasan}, skor {$skor}"
            . ($t === 3 ? ', hasil ke gudang Cadangan' : '');

        $base = $this->baris('buat', $t, $p);
        if ($simulasi) {
            return array_merge($base, ['simulasi' => true, 'reason' => "Antrean CNC kosong — akan membuat OP {$ringkas}."]);
        }

        $gudang    = $this->auto->gudangProduksi();
        $materials = $this->bomService->calculateMaterials($bom->id, $siklus);
        $outputs   = $this->bomService->calculateOutputs($bom->id, $siklus);

        try {
            $order = DB::transaction(function () use ($t, $p, $bom, $siklus, $gudang, $materials, $outputs, $rendah, $ringkas) {
                $order = $this->orderService->create([
                    'type'            => 'ready_stock',
                    'bom_id'          => $bom->id,
                    'warehouse_id'    => $gudang->id,
                    'production_date' => now()->format('Y-m-d'),
                    'planned_cycles'  => $siklus,
                    'score_type'      => $rendah ? 'priority' : 'auto',
                    'priority_level'  => $rendah ? 'low' : null,
                    'created_via'     => 'auto',
                    'kv_skill'        => self::penanda($t),
                    'description'     => $bom->description,
                    'notes'           => "Pengisi antrean CNC {$p->sku}: {$ringkas}.",
                    'materials'       => array_map(fn ($m) => [
                        'product_id' => $m['product_id'], 'qty_required' => $m['qty_required'], 'unit' => $m['unit'] ?? null,
                    ], $materials),
                    'outputs'         => array_map(fn ($o) => [
                        'product_id' => $o['product_id'], 'qty_planned' => $o['qty_planned'],
                        'output_type' => $o['output_type'], 'percentage' => $o['percentage'],
                    ], $outputs),
                    'steps'           => $this->bomService->getSteps($bom->id),
                ]);

                // Tingkat 3: modal Selesai otomatis mengarahkan hasil utama ke Cadangan.
                if ($t === 3) {
                    $utama = $order->outputs()->where('output_type', 'main')->first();
                    $utama?->update(['warehouse_allocations' => [[
                        'warehouse_id' => $this->gudangCadangan()->id, 'qty' => (float) $utama->qty_planned,
                    ]]]);
                }
                return $order;
            });

            try {
                $this->orderService->confirm($order->id);
                $statusNote = 'dikonfirmasi, siap dikerjakan';
            } catch (\Throwable $e) {
                Log::warning('IsiAntreanCncService: order dibuat tapi gagal dikonfirmasi otomatis', ['order_id' => $order->id, 'message' => $e->getMessage()]);
                $statusNote = 'tetap draft (konfirmasi otomatis gagal: ' . $e->getMessage() . ')';
            }

            return array_merge($base, [
                'created'      => true,
                'order_number' => $order->order_number,
                'reason'       => "Order produksi dibuat: {$ringkas} — {$statusNote}.",
            ]);
        } catch (\Throwable $e) {
            Log::error('IsiAntreanCncService gagal membuat order', ['product_id' => $p->id, 'message' => $e->getMessage()]);
            return array_merge($base, ['reason' => 'Gagal membuat order: ' . $e->getMessage()]);
        }
    }

    /**
     * Penyesuaian OP pengisi yang masih berjalan:
     *  - Tingkat 1 Prioritas Rendah yang kini satu-satunya OP produknya → Otomatis.
     *  - Tingkat 3 yang stok jual produknya tinggal ≤ T2_MAKS_HARI hari (atau ≤ min/habis)
     *    → hasil dialihkan ke gudang jual (alokasi Cadangan dihapus) + skor Otomatis.
     */
    public function sesuaikanOpBerjalan(): array
    {
        $hasil = [];
        $ops = ProductionOrder::with('outputs.product')
            ->whereIn('kv_skill', [self::penanda(1), self::penanda(3)])
            ->whereIn('status', ['draft', 'confirmed', 'in_progress', 'partial'])
            ->get();

        foreach ($ops as $op) {
            $utama = $op->outputs->firstWhere('output_type', 'main');
            $p     = $utama?->product;
            if (!$p) continue;

            if ($op->kv_skill === self::penanda(1)) {
                if ($op->score_type !== 'priority' || $op->priority_level !== 'low') continue;
                if ($this->auto->jumlahProduksiAktif($p) > 1) continue;
                $op->update(['score_type' => 'auto', 'priority_level' => null]);
                $hasil[] = $this->baris('naikkan', 1, $p, [
                    'diubah' => true, 'order_number' => $op->order_number,
                    'reason' => "OP andalan sebelumnya sudah selesai — skor OP ini dinaikkan dari Prioritas Rendah ke Otomatis.",
                ]);
                continue;
            }

            // Tingkat 3 — yang sudah dialihkan (alokasi kosong) tidak diperiksa lagi.
            if (empty($utama->warehouse_allocations)) continue;
            $tersedia = $this->auto->stokTersedia($p);
            $cukup    = LajuJualService::hariCukup($tersedia, $this->laju->perHari([$p->id])[$p->id] ?? 0);
            $min      = $p->min_stock !== null ? (float) $p->min_stock : null;
            $diborong = $tersedia <= 0 || ($min > 0 && $tersedia <= $min) || ($cukup !== null && $cukup <= self::T2_MAKS_HARI);
            if (!$diborong) continue;

            DB::transaction(function () use ($op, $utama) {
                $utama->update(['warehouse_allocations' => null]);
                $op->update(['score_type' => 'auto', 'priority_level' => null]);
            });
            $hasil[] = $this->baris('pindah', 3, $p, [
                'diubah' => true, 'order_number' => $op->order_number,
                'reason' => "Stok jual {$p->sku} tinggal " . rtrim(rtrim(number_format($tersedia, 1, ',', '.'), '0'), ',')
                    . ($cukup !== null ? ' (cukup ' . number_format($cukup, 1, ',', '.') . ' hari)' : '')
                    . ' — hasil OP cadangan dialihkan ke gudang jual, skor jadi Otomatis.',
            ]);
        }

        return $hasil;
    }

    /**
     * Produk ready aktif yang punya BOM berlangkah CNC (BOM pertama per produk; BOM alternatifnya
     * dipilih di calon() lewat AutoProductionService::pilihBom).
     *
     * @return array<int, array{id: int, produk: Product, bom: Bom}>
     */
    private function produkCnc(): array
    {
        $cnc = $this->antrean->departemen('CNC');
        if (!$cnc) return [];

        $boms = Bom::with(['outputs.product'])
            ->whereHas('steps', fn ($q) => $q->where('department_id', $cnc->id))
            ->orderBy('id')
            ->get();

        $hasil = [];
        foreach ($boms as $bom) {
            $p = $bom->outputs->firstWhere('output_type', 'main')?->product;
            if (!$p || $p->sale_type !== 'ready' || !$p->is_active || isset($hasil[$p->id])) continue;
            $hasil[$p->id] = ['id' => $p->id, 'produk' => $p, 'bom' => $bom];
        }
        return array_values($hasil);
    }

    public function gudangCadangan(): ?Warehouse
    {
        return Warehouse::where('is_active', 1)->where('is_sellable', 0)
            ->where('name', 'like', '%cadangan%')->orderBy('id')->first();
    }

    private function stokDi(Product $p, Warehouse $w): float
    {
        return (float) ProductStock::where('product_id', $p->id)->where('warehouse_id', $w->id)->sum('qty_on_hand');
    }

    private function baris(string $jenis, int $tingkat, ?Product $p, array $isi = []): array
    {
        return array_merge([
            'jenis' => $jenis, 'tingkat' => $tingkat,
            'product_id' => $p?->id, 'sku' => $p?->sku ?? ($tingkat ? "Tingkat {$tingkat}" : 'Antrean CNC'), 'nama' => $p?->name ?? '',
            'created' => false, 'simulasi' => false, 'diubah' => false, 'order_number' => null, 'reason' => null,
        ], $isi);
    }
}
