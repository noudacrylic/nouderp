<?php

namespace App\Modules\Production\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OP Nama Meja (NM-*) dari bahan setengah jadi BNM-* (BNM-7X20-M1 → NM-7X20-M1).
 *
 * BNM lahir sebagai produk sampingan OP lain, jadi NM tidak dibuat dari BOM-nya
 * sendiri selama BNM masih ada. Saat NM menipis (pemicu sama dengan stok menipis):
 *   BNM ≥ 150      → OP 150 pcs, ambil 150 BNM
 *   BNM 50 – 149   → OP sebanyak seluruh BNM
 *   BNM < 50       → seluruh BNM + potong lembar dari BOM NM; siklus dipilih agar
 *                    total paling dekat ke 150 (22 BNM + 2 × 64 = 150), minimal 1.
 *                    NM tanpa BOM potong (mis. price tag) cukup dari BNM yang ada.
 * Skor antrean OP = otomatis.
 *
 * Pengisi antrean (isiAntreanRakit): saat divisi Assembling tak punya pekerjaan di jam
 * kerja, rakit BNM → NM untuk NM berstok terendah — min(150, BNM), BNM minimal 10,
 * tanpa potong lembar, tanpa menunggu NM menipis. Prioritas Rendah; naik ke Otomatis
 * begitu NM-nya menipis selagi OP masih berjalan.
 */
class NmDariBnmService
{
    public const TARGET  = 150;
    public const MIN_BNM = 50;

    /** Pengisi antrean: BNM di bawah ini tidak dibuatkan OP (hindari OP 3 pcs). */
    public const PENGISI_MIN_BNM = 10;
    /** Jam kerja pengisi: Senin–Sabtu 08:00–16:00 (malam/libur antrean pasti kosong). */
    public const JAM_KERJA_HARI    = [1, 2, 3, 4, 5, 6];
    public const JAM_KERJA_MULAI   = '08:00';
    public const JAM_KERJA_SELESAI = '16:00';

    public function __construct(
        private AutoProductionService $auto,
        private BomService $bomService,
        private ProductionOrderService $orderService,
    ) {}

    /** Semua NM ready yang punya pasangan BNM. */
    public function pasangan(): array
    {
        $nm = Product::where('sku', 'like', 'NM-%')
            ->where('sale_type', 'ready')
            ->where('is_active', true)
            ->orderBy('sku')
            ->get();
        $bnm = Product::whereIn('sku', $nm->map(fn ($p) => 'B' . $p->sku))->get()
            ->keyBy(fn ($p) => strtoupper($p->sku));

        return $nm->map(fn ($p) => ['nm' => $p, 'bnm' => $bnm->get(strtoupper('B' . $p->sku))])
            ->filter(fn ($x) => $x['bnm'] !== null)
            ->values()->all();
    }

    /** $simulasi = true → semua pemeriksaan jalan, OP tidak dibuat (baris ditandai 'simulasi'). */
    public function runAll(bool $simulasi = false): array
    {
        return array_map(fn ($x) => $this->runForNm($x['nm'], $simulasi), $this->pasangan());
    }

    public function runForNm(Product $nm, bool $simulasi = false): array
    {
        $base = [
            'product_id'   => $nm->id,
            'sku'          => $nm->sku,
            'nama'         => $nm->name,
            'created'      => false,
            'simulasi'     => false,
            'order_number' => null,
            'reason'       => null,
        ];

        $bnm = Product::where('sku', 'B' . $nm->sku)->first();
        if (!$bnm) {
            return array_merge($base, ['reason' => "Tidak ada produk B{$nm->sku}."]);
        }

        $bom = Bom::with('outputs')
            ->whereHas('outputs', fn ($q) => $q->where('product_id', $nm->id)->where('output_type', 'main'))
            ->orderBy('id')
            ->first();
        // BOM NM yang auto-produksinya aktif sudah diurus skill stok menipis — jangan berebut.
        if ($bom?->auto_production) {
            return array_merge($base, ['reason' => "BOM {$bom->bom_number} auto-produksi aktif — ditangani skill stok menipis."]);
        }

        $tersedia = $this->auto->stokTersedia($nm);
        $min      = $nm->min_stock !== null ? (float) $nm->min_stock : null;
        if ($tersedia > 0 && !($min > 0 && $tersedia <= $min)) {
            return array_merge($base, ['reason' => "Stok {$nm->sku} ({$tersedia}) masih aman (" . ($min > 0 ? "min {$min}" : 'min_stock belum di-set') . ').']);
        }

        if ($this->auto->adaProduksiAktif($nm)) {
            return array_merge($base, ['reason' => "Sudah ada produksi aktif untuk produk {$nm->sku}."]);
        }

        $gudang = $this->auto->gudangProduksi();
        if (!$gudang) {
            return array_merge($base, ['reason' => 'Tidak ada gudang aktif untuk dipakai.']);
        }

        $rencana = $this->rencana($nm, $bnm, $bom, $gudang);
        if (isset($rencana['lewati'])) {
            return array_merge($base, ['reason' => $rencana['lewati']]);
        }

        $pemicu = $tersedia <= 0 ? ($tersedia < 0 ? "stok minus {$tersedia}" : 'stok habis') : "stok {$tersedia} <= min {$min}";

        if ($simulasi) {
            return array_merge($base, ['simulasi' => true, 'reason' => "Akan membuat OP {$rencana['ringkas']} ({$pemicu})."]);
        }

        return $this->buatDanKonfirmasi($base, [
            'type'            => 'ready_stock',
            'bom_id'          => $rencana['bom']?->id,
            'warehouse_id'    => $gudang->id,
            'production_date' => now()->format('Y-m-d'),
            'planned_cycles'  => $rencana['siklus'] ?: 1,
            'score_type'      => 'auto',
            'created_via'     => 'auto',
            'description'     => $rencana['bom']?->description,
            'notes'           => "Order otomatis NM dari BNM ({$nm->sku}: {$pemicu}). {$rencana['ringkas']}.",
            'materials'       => $rencana['materials'],
            'outputs'         => $rencana['outputs'],
            'steps'           => $rencana['steps'],
        ], "{$rencana['ringkas']} ({$pemicu})");
    }

    /**
     * Buat OP lalu langsung konfirmasi (BNM & lembar diambil dari stok), seperti stok menipis.
     * Gagal konfirmasi → OP tetap draft; gagal membuat → baris "Gagal ...".
     */
    private function buatDanKonfirmasi(array $base, array $data, string $keterangan): array
    {
        try {
            $order = DB::transaction(fn () => $this->orderService->create($data));

            try {
                $this->orderService->confirm($order->id);
                $statusNote = 'dikonfirmasi, siap dikerjakan';
            } catch (\Throwable $confirmError) {
                Log::warning('NmDariBnmService: order dibuat tapi gagal dikonfirmasi otomatis', [
                    'order_id' => $order->id,
                    'message'  => $confirmError->getMessage(),
                ]);
                $statusNote = 'tetap draft (konfirmasi otomatis gagal: ' . $confirmError->getMessage() . ')';
            }

            return array_merge($base, [
                'created'      => true,
                'order_number' => $order->order_number,
                'reason'       => "Order produksi dibuat: {$keterangan} — {$statusNote}.",
            ]);
        } catch (\Throwable $e) {
            Log::error('NmDariBnmService gagal membuat order', ['product_id' => $base['product_id'], 'message' => $e->getMessage()]);
            return array_merge($base, ['reason' => 'Gagal membuat order: ' . $e->getMessage()]);
        }
    }

    /**
     * Isi OP menurut aturan 150 / 50–149 / <50, atau ['lewati' => alasan].
     *
     * @return array{bom: ?Bom, siklus: int, materials: array, outputs: array, steps: array, ringkas: string}|array{lewati: string}
     */
    public function rencana(Product $nm, Product $bnm, ?Bom $bom, Warehouse $gudang): array
    {
        $stokBnm = $this->stokBnm($bnm, $gudang);
        $ambil   = (int) min(self::TARGET, $stokBnm);
        $siklus  = 0;
        $perSiklus = 0.0;

        if ($stokBnm < self::MIN_BNM && $bom) {
            $perSiklus = (float) ($bom->outputs->firstWhere('output_type', 'main')?->qty_per_cycle ?? 0);
            if ($perSiklus > 0) {
                $siklus = max(1, (int) round((self::TARGET - $ambil) / $perSiklus));
            }
        }

        if ($ambil <= 0 && $siklus === 0) {
            return ['lewati' => "{$bnm->sku} habis dan {$nm->sku} tidak punya BOM potong lembar."];
        }

        $materials = [];
        $outputs   = [];
        $steps     = [];

        if ($siklus > 0) {
            $materials = array_map(fn ($m) => [
                'product_id' => $m['product_id'], 'qty_required' => $m['qty_required'], 'unit' => $m['unit'] ?? null,
            ], $this->bomService->calculateMaterials($bom->id, $siklus));
            $outputs = array_map(fn ($o) => [
                'product_id'  => $o['product_id'],
                'qty_planned' => $o['qty_planned'] + ($o['output_type'] === 'main' ? $ambil : 0),
                'output_type' => $o['output_type'],
                'percentage'  => $o['percentage'],
            ], $this->bomService->calculateOutputs($bom->id, $siklus));
            $steps = $this->bomService->getSteps($bom->id);
        } else {
            $outputs = [['product_id' => $nm->id, 'qty_planned' => $ambil, 'output_type' => 'main', 'percentage' => 100]];
            $steps   = $this->langkahRakit($bom);
            if (!$steps) {
                return ['lewati' => 'Departemen "Assembling" tidak ditemukan untuk langkah OP.'];
            }
        }

        if ($ambil > 0) {
            $materials[] = ['product_id' => $bnm->id, 'qty_required' => $ambil, 'unit' => $bnm->base_unit];
        }

        $total = $ambil + (int) round($siklus * $perSiklus);
        $ringkas = match (true) {
            $siklus > 0 && $ambil > 0 => "{$total} pcs = {$ambil} {$bnm->sku} (stok {$stokBnm} < " . self::MIN_BNM . ") + {$siklus} siklus potong {$bom->bom_number} @" . (int) $perSiklus,
            $siklus > 0               => "{$total} pcs = {$bnm->sku} habis, {$siklus} siklus potong {$bom->bom_number} @" . (int) $perSiklus,
            $stokBnm >= self::TARGET  => "{$ambil} pcs dari {$ambil} {$bnm->sku} (stok {$stokBnm})",
            $stokBnm >= self::MIN_BNM => "{$ambil} pcs dari seluruh {$bnm->sku}",
            default                   => "{$ambil} pcs dari seluruh {$bnm->sku} (tanpa BOM potong lembar)",
        };

        return ['bom' => $siklus > 0 ? $bom : null, 'siklus' => $siklus] + compact('materials', 'outputs', 'steps', 'ringkas');
    }

    /** BNM yang bisa diambil OP: stok di gudang OP (konfirmasi mengambil dari sana) − reservasi aktif. */
    public function stokBnm(Product $bnm, Warehouse $gudang): int
    {
        $onHand = (float) ProductStock::where('product_id', $bnm->id)->where('warehouse_id', $gudang->id)->sum('qty_on_hand');
        $reserved = (float) StockReservation::where('product_id', $bnm->id)->where('warehouse_id', $gudang->id)
            ->where('status', 'active')->sum('qty');

        return max(0, (int) floor($onHand - $reserved));
    }

    /** NM dari BNM tinggal dirakit: langkah terakhir BOM NM bila ada, atau departemen Assembling. */
    private function langkahRakit(?Bom $bom): array
    {
        if ($bom && ($terakhir = collect($this->bomService->getSteps($bom->id))->last())) {
            return [$terakhir];
        }
        $dept = $this->departemenRakit();

        return $dept ? [['name' => 'Assembling', 'department_id' => $dept->id, 'department_name' => $dept->name]] : [];
    }

    /**
     * Pengisi antrean Assembling. Baris hasil:
     *   - 'jenis' => 'naikkan' : OP pengisi yang skornya dinaikkan ke Otomatis (NM menipis)
     *   - 'jenis' => 'antrean' : keadaan antrean (masih berisi, di luar jam kerja, dst.)
     *   - 'jenis' => 'buat'    : OP pengisi dibuat / akan dibuat (simulasi)
     *
     * $naikkanSkor = false → OP pengisi lama tidak diubah (mode Bayangan).
     */
    public function isiAntreanRakit(bool $simulasi = false, bool $naikkanSkor = true, ?Carbon $saat = null): array
    {
        $hasil = $naikkanSkor ? $this->naikkanPengisiMenipis() : [];
        $saat ??= now();

        $lewati = $this->alasanTakPerluPengisi($saat);
        if ($lewati !== null) {
            return [...$hasil, $this->barisAntrean($lewati)];
        }

        $calon = $this->calonPengisi();
        if (!$calon) {
            return [...$hasil, $this->barisAntrean('Antrean Assembling kosong, tapi tidak ada BNM ≥ ' . self::PENGISI_MIN_BNM
                . ' pcs yang NM-nya belum punya OP berjalan.')];
        }

        return [...$hasil, $this->buatPengisi($calon['nm'], $calon['bnm'], $simulasi)];
    }

    /**
     * Usulan pengisi disetujui: periksa ulang antrean & NM-nya (jam kerja tidak — manusia
     * sudah memutuskan), lalu buat OP dengan stok BNM terbaru.
     */
    public function isiAntreanRakitUntuk(Product $nm): array
    {
        $rakit = $this->departemenRakit();
        if ($rakit && ($n = $this->jumlahAntrean($rakit)) > 0) {
            return array_merge($this->barisBuat($nm), ['reason' => "Antrean Assembling sudah berisi {$n} pekerjaan."]);
        }
        $bnm = Product::where('sku', 'B' . $nm->sku)->first();
        if (!$bnm) {
            return array_merge($this->barisBuat($nm), ['reason' => "Tidak ada produk B{$nm->sku}."]);
        }

        return $this->buatPengisi($nm, $bnm, false);
    }

    /** null = antrean kosong & boleh diisi; selain itu alasan dilewati. */
    private function alasanTakPerluPengisi(Carbon $saat): ?string
    {
        if (!$this->jamKerja($saat)) {
            return 'Di luar jam kerja (' . $saat->translatedFormat('l H:i') . ') — antrean tidak diisi.';
        }
        $rakit = $this->departemenRakit();
        if (!$rakit) {
            return 'Departemen "Assembling" tidak ditemukan.';
        }
        if (($n = $this->jumlahAntrean($rakit)) > 0) {
            return "Antrean Assembling masih berisi {$n} pekerjaan.";
        }
        // Pengisi yang gagal dikonfirmasi tetap draft & tak tampil di antrean — tanpa rem ini
        // tiap 30 menit lahir pengisi baru untuk NM berikutnya.
        $draft = $this->pengisiQuery()->where('status', 'draft')->value('order_number');
        if ($draft) {
            return "OP pengisi {$draft} masih draft (belum dikonfirmasi) — selesaikan dulu.";
        }

        return null;
    }

    public function jamKerja(Carbon $saat): bool
    {
        return in_array($saat->dayOfWeekIso, self::JAM_KERJA_HARI, true)
            && $saat->format('H:i') >= self::JAM_KERJA_MULAI
            && $saat->format('H:i') < self::JAM_KERJA_SELESAI;
    }

    /**
     * Jumlah pekerjaan di antrean divisi, definisi sama dengan halaman Proses Produksi
     * (ProductionProcessController::index): langkah pending yang langkah sebelumnya
     * sudah selesai, sedang dikerjakan, atau dijeda — di OP confirmed/in_progress/partial.
     */
    public function jumlahAntrean(Department $dept): int
    {
        return ProductionOrderStep::with('productionOrder.steps')
            ->where('department_id', $dept->id)
            ->whereIn('status', ['pending', 'in_progress', 'paused'])
            ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', ['confirmed', 'in_progress', 'partial']))
            ->get()
            ->filter(function ($step) {
                if ($step->status !== 'pending') return true;
                $prev = $step->productionOrder->steps->firstWhere('step_number', $step->step_number - 1);
                return $prev === null || $prev->status === 'completed';
            })
            ->count();
    }

    /** NM berstok tersedia terendah yang BNM-nya ≥ PENGISI_MIN_BNM dan belum punya OP berjalan. */
    private function calonPengisi(): ?array
    {
        $gudang = $this->auto->gudangProduksi();
        if (!$gudang) return null;

        return collect($this->pasangan())
            ->filter(fn ($x) => $this->stokBnm($x['bnm'], $gudang) >= self::PENGISI_MIN_BNM
                && !$this->auto->adaProduksiAktif($x['nm']))
            ->sortBy(fn ($x) => $this->auto->stokTersedia($x['nm']))
            ->first();
    }

    private function buatPengisi(Product $nm, Product $bnm, bool $simulasi): array
    {
        $base   = $this->barisBuat($nm);
        $gudang = $this->auto->gudangProduksi();
        if (!$gudang) {
            return array_merge($base, ['reason' => 'Tidak ada gudang aktif untuk dipakai.']);
        }
        if ($this->auto->adaProduksiAktif($nm)) {
            return array_merge($base, ['reason' => "Sudah ada produksi aktif untuk produk {$nm->sku}."]);
        }
        $stokBnm = $this->stokBnm($bnm, $gudang);
        if ($stokBnm < self::PENGISI_MIN_BNM) {
            return array_merge($base, ['reason' => "{$bnm->sku} tinggal {$stokBnm} (< " . self::PENGISI_MIN_BNM . ').']);
        }
        $steps = $this->langkahRakit(null);
        if (!$steps) {
            return array_merge($base, ['reason' => 'Departemen "Assembling" tidak ditemukan untuk langkah OP.']);
        }

        $qty      = (int) min(self::TARGET, $stokBnm);
        $tersedia = $this->auto->stokTersedia($nm);
        $ringkas  = "{$qty} pcs dari {$qty} {$bnm->sku} (stok BNM {$stokBnm}; stok {$nm->sku} {$tersedia}, terendah)";

        if ($simulasi) {
            return array_merge($base, ['simulasi' => true, 'reason' => "Antrean Assembling kosong — akan membuat OP pengisi {$ringkas}, prioritas rendah."]);
        }

        return $this->buatDanKonfirmasi($base, [
            'type'            => 'ready_stock',
            'bom_id'          => null,
            'warehouse_id'    => $gudang->id,
            'production_date' => now()->format('Y-m-d'),
            'planned_cycles'  => 1,
            'score_type'      => 'priority',
            'priority_level'  => 'low',
            'created_via'     => 'auto',
            'notes'           => "Pengisi antrean Assembling: {$ringkas}. Prioritas rendah; naik ke Otomatis bila {$nm->sku} menipis.",
            'materials'       => [['product_id' => $bnm->id, 'qty_required' => $qty, 'unit' => $bnm->base_unit]],
            'outputs'         => [['product_id' => $nm->id, 'qty_planned' => $qty, 'output_type' => 'main', 'percentage' => 100]],
            'steps'           => $steps,
        ], "pengisi antrean {$ringkas}, prioritas rendah");
    }

    /** OP pengisi yang masih berjalan dan NM-nya kini menipis/habis → skor Otomatis. */
    public function naikkanPengisiMenipis(): array
    {
        $hasil = [];
        $ops = $this->pengisiQuery()->whereIn('status', ['draft', 'confirmed', 'in_progress', 'partial'])->with('outputs.product')->get();
        foreach ($ops as $op) {
            $nm = $op->outputs->firstWhere('output_type', 'main')?->product;
            if (!$nm) continue;
            $tersedia = $this->auto->stokTersedia($nm);
            $min      = $nm->min_stock !== null ? (float) $nm->min_stock : null;
            if ($tersedia > 0 && !($min > 0 && $tersedia <= $min)) continue;

            $op->update(['score_type' => 'auto', 'priority_level' => null]);
            $hasil[] = array_merge($this->barisBuat($nm), [
                'jenis'        => 'naikkan',
                'diubah'       => true,
                'order_number' => $op->order_number,
                'reason'       => "Skor antrean OP pengisi dinaikkan dari Prioritas Rendah ke Otomatis: stok {$nm->sku} {$tersedia}"
                    . ($tersedia > 0 ? " <= min {$min}" : '') . '.',
            ]);
        }
        return $hasil;
    }

    /**
     * OP pengisi = OP otomatis berprioritas Rendah. Hanya pengisi antrean yang membuat
     * kombinasi ini; kalau manusia mengubah skornya, OP itu tak lagi diurus di sini.
     */
    private function pengisiQuery()
    {
        return ProductionOrder::where('created_via', 'auto')
            ->where('score_type', 'priority')
            ->where('priority_level', 'low');
    }

    private function departemenRakit(): ?Department
    {
        return Department::where('name', 'like', 'Assembl%')->where('is_active', true)->first();
    }

    private function barisBuat(Product $nm): array
    {
        return [
            'jenis' => 'buat', 'product_id' => $nm->id, 'sku' => $nm->sku, 'nama' => $nm->name,
            'created' => false, 'simulasi' => false, 'order_number' => null, 'reason' => null,
        ];
    }

    private function barisAntrean(string $alasan): array
    {
        return [
            'jenis' => 'antrean', 'product_id' => null, 'sku' => 'Antrean Assembling', 'nama' => '',
            'created' => false, 'simulasi' => false, 'order_number' => null, 'reason' => $alasan,
        ];
    }
}
