<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomMaterial;
use App\Modules\Production\Models\BomOutput;
use App\Modules\Production\Models\BomStep;
use App\Modules\Production\Models\ProductionByproduct;
use App\Core\Inventory\Product;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;
use Exception;

class BomService
{
    public function __construct(private BomScoreService $scoreService) {}

    public function create(array $data): Bom
    {
        return DB::transaction(function () use ($data) {
            $autoProduction = (bool) ($data['auto_production'] ?? false);

            // Persentase output dihitung server-side dari master Produk Sampingan
            // (otoritatif): sampingan = unit% × qty/siklus, utama = sisa.
            $data['outputs'] = $this->applyByproductPercentages($data['outputs'] ?? []);
            $this->assertOutputsValid($data['outputs']);
            $this->assertNoMaterialOutputOverlap($data['materials'] ?? [], $data['outputs']);
            if ($autoProduction) {
                $this->assertUniqueAutoProduction(null, $data['outputs']);
            }

            $bom = Bom::create([
                'bom_number'     => NumberGeneratorService::generate('BOM'),
                'name'           => $data['name'],
                'description'    => $data['description'] ?? null,
                'typical_cycles' => $data['typical_cycles'] ?? null,
                'auto_production' => $autoProduction,
                'score'          => 0,
            ]);

            $this->syncMaterials($bom, $data['materials'] ?? []);
            $this->syncOutputs($bom, $data['outputs'] ?? []);
            $this->syncSteps($bom, $data['steps'] ?? []);

            $score = $this->scoreService->calculate($bom->fresh(['outputs.product']));
            $bom->update(['score' => $score]);

            return $bom;
        });
    }

    public function update(int $id, array $data): Bom
    {
        return DB::transaction(function () use ($id, $data) {
            $bom = Bom::findOrFail($id);
            $autoProduction = (bool) ($data['auto_production'] ?? false);

            $data['outputs'] = $this->applyByproductPercentages($data['outputs'] ?? []);
            $this->assertOutputsValid($data['outputs']);
            $this->assertNoMaterialOutputOverlap($data['materials'] ?? [], $data['outputs']);
            if ($autoProduction) {
                $this->assertUniqueAutoProduction($bom->id, $data['outputs']);
            }

            $bom->update([
                'name'           => $data['name'],
                'description'    => $data['description'] ?? null,
                'typical_cycles' => $data['typical_cycles'] ?? null,
                'auto_production' => $autoProduction,
            ]);

            $this->syncMaterials($bom, $data['materials'] ?? []);
            $this->syncOutputs($bom, $data['outputs'] ?? []);
            $this->syncSteps($bom, $data['steps'] ?? []);

            $score = $this->scoreService->calculate($bom->fresh(['outputs.product']));
            $bom->update(['score' => $score]);

            return $bom;
        });
    }

    /**
     * Toggle auto_production untuk BOM tunggal (dipanggil dari index/show via tombol).
     */
    public function setAutoProduction(int $bomId, bool $value): Bom
    {
        return DB::transaction(function () use ($bomId, $value) {
            $bom = Bom::with('outputs')->findOrFail($bomId);

            if ($value) {
                $mainOutput = $bom->outputs->firstWhere('output_type', 'main');
                if (!$mainOutput) {
                    throw new Exception('BOM tidak memiliki output utama. Auto-produksi tidak bisa diaktifkan.');
                }
                $exists = Bom::where('id', '!=', $bom->id)
                    ->where('auto_production', true)
                    ->whereHas('outputs', fn($q) => $q->where('output_type', 'main')
                                                      ->where('product_id', $mainOutput->product_id))
                    ->first();
                if ($exists) {
                    throw new Exception("Produk utama ini sudah diaktifkan auto-produksinya di BOM {$exists->bom_number}. Nonaktifkan dulu di sana.");
                }

                $this->assertPreorderConstraints([
                    [
                        'output_type'   => 'main',
                        'product_id'    => $mainOutput->product_id,
                        'qty_per_cycle' => $mainOutput->qty_per_cycle,
                    ],
                ]);
            }

            $bom->update(['auto_production' => $value]);
            return $bom;
        });
    }

    /**
     * Hitung persentase output secara otoritatif dari master Produk Sampingan.
     *  - Sampingan: unit_percentage = persentase master (per unit/siklus);
     *               percentage = unit_percentage × qty_per_cycle.
     *  - Utama: unit_percentage = null; percentage = 100 − Σ sampingan.
     * Produk sampingan wajib terdaftar di production_byproducts.
     */
    private function applyByproductPercentages(array $outputs): array
    {
        $outputs = array_values($outputs);

        $byIds = collect($outputs)
            ->filter(fn($o) => !empty($o['product_id']) && ($o['output_type'] ?? 'main') === 'by_product')
            ->pluck('product_id')->map(fn($id) => (int) $id)->unique();

        $masters = ProductionByproduct::whereIn('product_id', $byIds)->get()->keyBy('product_id');

        $sumByproduct = 0.0;
        foreach ($outputs as $i => $o) {
            if (empty($o['product_id'])) continue;
            if (($o['output_type'] ?? 'main') !== 'by_product') continue;

            $pid    = (int) $o['product_id'];
            $master = $masters->get($pid);
            if (!$master) {
                $p     = Product::find($pid);
                $label = $p ? trim(($p->sku ? $p->sku . ' - ' : '') . $p->name) : "#$pid";
                throw new Exception("Produk \"{$label}\" belum terdaftar sebagai Produk Sampingan di Pengaturan Produksi.");
            }

            $unitPct = (float) $master->percentage;
            $qty     = (float) ($o['qty_per_cycle'] ?? 0);
            $pct     = round($unitPct * $qty, 4);

            $outputs[$i]['unit_percentage'] = $unitPct;
            $outputs[$i]['percentage']      = $pct;
            $sumByproduct += $pct;
        }

        if ($sumByproduct > 100 + 0.01) {
            $shown = rtrim(rtrim(number_format($sumByproduct, 4, '.', ''), '0'), '.');
            throw new Exception("Total persentase produk sampingan melebihi 100% (saat ini {$shown}%). Kurangi qty atau persentase sampingan.");
        }

        $mainPct = round(100 - $sumByproduct, 4);
        foreach ($outputs as $i => $o) {
            if (empty($o['product_id'])) continue;
            if (($o['output_type'] ?? 'main') === 'main') {
                $outputs[$i]['unit_percentage'] = null;
                $outputs[$i]['percentage']      = $mainPct;
            }
        }

        return $outputs;
    }

    /**
     * Guard integritas output BOM (server-side, mirror validasi klien di create.blade.php).
     * Berlaku untuk SEMUA BOM, bukan hanya yang auto-produksi:
     *  - Produk utama wajib tepat 1 (distribusi biaya & skor mengandalkan satu output utama).
     *  - Total persentase output wajib 100%.
     */
    private function assertOutputsValid(array $outputs): void
    {
        $outputs = array_values(array_filter($outputs, fn($o) => !empty($o['product_id'])));
        if (count($outputs) === 0) {
            throw new Exception('BOM harus memiliki minimal satu produk output.');
        }

        $mainCount = collect($outputs)->where('output_type', 'main')->count();
        if ($mainCount === 0) {
            throw new Exception('BOM harus memiliki tepat satu produk utama. Tandai salah satu output sebagai "Utama".');
        }
        if ($mainCount > 1) {
            throw new Exception("Produk utama hanya boleh satu per BOM (saat ini ada {$mainCount}). Ubah sisanya menjadi \"Sampingan\".");
        }

        $totalPct = collect($outputs)->sum(fn($o) => (float) ($o['percentage'] ?? 0));
        if (abs($totalPct - 100) > 0.01) {
            $shown = rtrim(rtrim(number_format($totalPct, 2, '.', ''), '0'), '.');
            throw new Exception("Total persentase output harus 100% (saat ini {$shown}%).");
        }
    }

    /**
     * Produk yang sama tidak boleh muncul sebagai bahan baku DAN output dalam satu BOM
     * (mencegah BOM sirkular: produk dikonsumsi sebagai input sekaligus dihasilkan).
     */
    private function assertNoMaterialOutputOverlap(array $materials, array $outputs): void
    {
        $materialIds = collect($materials)->pluck('product_id')->filter()->map(fn($id) => (int) $id);
        $outputIds   = collect($outputs)->pluck('product_id')->filter()->map(fn($id) => (int) $id);

        $overlap = $materialIds->intersect($outputIds)->unique()->values();
        if ($overlap->isEmpty()) {
            return;
        }

        $names = Product::whereIn('id', $overlap)
            ->get()
            ->map(fn($p) => trim(($p->sku ? $p->sku . ' - ' : '') . $p->name))
            ->implode(', ');

        throw new Exception("Produk tidak boleh menjadi bahan baku sekaligus output dalam satu BOM: {$names}.");
    }

    /**
     * Pastikan tidak ada BOM lain dengan auto_production=true yang punya produk utama sama.
     * Dipanggil saat create/update BOM dengan toggle on.
     *
     * @param int|null $excludeBomId  id BOM yang sedang di-update (skip dari pengecekan)
     * @param array    $outputs       array outputs dari form (harus punya output dengan output_type=main)
     */
    private function assertUniqueAutoProduction(?int $excludeBomId, array $outputs): void
    {
        $mainOutput = collect($outputs)->firstWhere('output_type', 'main');
        if (!$mainOutput || empty($mainOutput['product_id'])) {
            throw new Exception('BOM harus memiliki output utama untuk dapat mengaktifkan auto-produksi.');
        }

        $exists = Bom::where('auto_production', true)
            ->when($excludeBomId, fn($q) => $q->where('id', '!=', $excludeBomId))
            ->whereHas('outputs', fn($q) => $q->where('output_type', 'main')
                                              ->where('product_id', $mainOutput['product_id']))
            ->first();

        if ($exists) {
            throw new Exception("Produk utama ini sudah diaktifkan auto-produksinya di BOM {$exists->bom_number}. Nonaktifkan dulu di sana.");
        }

        $this->assertPreorderConstraints($outputs);
    }

    /**
     * Untuk produk preorder, output utama harus qty_per_cycle = 1
     * (1 unit pesanan = 1 siklus produksi pada auto-produksi pre-order).
     */
    private function assertPreorderConstraints(array $outputs): void
    {
        $mainOutput = collect($outputs)->firstWhere('output_type', 'main');
        if (!$mainOutput || empty($mainOutput['product_id'])) {
            return;
        }

        $product = Product::find($mainOutput['product_id']);
        if (!$product || $product->sale_type !== 'preorder') {
            return;
        }

        $qty = (float) ($mainOutput['qty_per_cycle'] ?? 0);
        if (abs($qty - 1.0) > 0.0001) {
            throw new Exception(
                "Produk preorder ({$product->sku}): output utama qty per siklus harus = 1 (saat ini {$qty}). ".
                "Untuk preorder, 1 unit pesanan = 1 siklus produksi."
            );
        }
    }

    public function delete(int $id): void
    {
        $bom = Bom::findOrFail($id);
        $bom->delete();
    }

    public function clone(int $id): Bom
    {
        return DB::transaction(function () use ($id) {
            $source = Bom::with(['materials', 'outputs', 'steps'])->findOrFail($id);

            $newBom = Bom::create([
                'bom_number'     => NumberGeneratorService::generate('BOM'),
                'name'           => $source->name . ' (Salinan)',
                'description'    => $source->description,
                'typical_cycles' => $source->typical_cycles,
                'score'          => 0,
            ]);

            foreach ($source->materials as $m) {
                BomMaterial::create([
                    'bom_id'        => $newBom->id,
                    'product_id'    => $m->product_id,
                    'qty_per_cycle' => $m->qty_per_cycle,
                    'unit'          => $m->unit,
                    'notes'         => $m->notes,
                ]);
            }

            foreach ($source->outputs as $o) {
                BomOutput::create([
                    'bom_id'        => $newBom->id,
                    'product_id'    => $o->product_id,
                    'qty_per_cycle' => $o->qty_per_cycle,
                    'output_type'   => $o->output_type,
                    'percentage'    => $o->percentage,
                    'notes'         => $o->notes,
                ]);
            }

            foreach ($source->steps as $s) {
                BomStep::create([
                    'bom_id'           => $newBom->id,
                    'step_number'      => $s->step_number,
                    'department_id'    => $s->department_id,
                    'name'             => $s->name,
                    'description'      => $s->description,
                    'estimated_hours'  => $s->estimated_hours,
                ]);
            }

            return $newBom;
        });
    }

    public function getSteps(int $bomId): array
    {
        $bom = Bom::with('steps.department')->findOrFail($bomId);

        return $bom->steps->map(fn($s) => [
            'name'            => $s->name,
            'department_id'   => $s->department_id,
            'department_name' => $s->department?->name ?? '',
        ])->values()->toArray();
    }

    /**
     * Hitung kebutuhan material untuk sejumlah siklus.
     * Return: [['product_id' => X, 'product_name' => Y, 'qty_required' => Z, 'unit' => U], ...]
     */
    public function calculateMaterials(int $bomId, float $cycles): array
    {
        $bom = Bom::with('materials.product')->findOrFail($bomId);

        return $bom->materials->map(fn($m) => [
            'product_id'   => $m->product_id,
            'product_name' => $m->product?->name ?? '-',
            'product_sku'  => $m->product?->sku ?? '-',
            'qty_required' => round($m->qty_per_cycle * $cycles, 4),
            'unit'         => $m->unit,
        ])->values()->toArray();
    }

    /**
     * Hitung output untuk sejumlah siklus.
     */
    public function calculateOutputs(int $bomId, float $cycles): array
    {
        $bom = Bom::with('outputs.product')->findOrFail($bomId);

        return $bom->outputs->map(fn($o) => [
            'product_id'      => $o->product_id,
            'product_name'    => $o->product?->name ?? '-',
            'product_sku'     => $o->product?->sku ?? '-',
            'qty_planned'     => round($o->qty_per_cycle * $cycles, 4),
            'output_type'     => $o->output_type,
            'percentage'      => $o->percentage,
            'unit_percentage' => $o->unit_percentage,
        ])->values()->toArray();
    }

    private function syncMaterials(Bom $bom, array $materials): void
    {
        $bom->materials()->delete();

        foreach ($materials as $m) {
            if (empty($m['product_id']) || empty($m['qty_per_cycle'])) continue;

            BomMaterial::create([
                'bom_id'        => $bom->id,
                'product_id'    => $m['product_id'],
                'qty_per_cycle' => $m['qty_per_cycle'],
                'unit'          => $m['unit'] ?? null,
                'notes'         => $m['notes'] ?? null,
            ]);
        }
    }

    private function syncOutputs(Bom $bom, array $outputs): void
    {
        $bom->outputs()->delete();

        foreach ($outputs as $o) {
            if (empty($o['product_id']) || empty($o['qty_per_cycle'])) continue;

            BomOutput::create([
                'bom_id'          => $bom->id,
                'product_id'      => $o['product_id'],
                'qty_per_cycle'   => $o['qty_per_cycle'],
                'output_type'     => $o['output_type'] ?? 'main',
                'percentage'      => $o['percentage'] ?? 100,
                'unit_percentage' => $o['unit_percentage'] ?? null,
                'notes'           => $o['notes'] ?? null,
            ]);
        }
    }

    private function syncSteps(Bom $bom, array $steps): void
    {
        $bom->steps()->delete();

        foreach ($steps as $i => $s) {
            if (empty($s['name'])) continue;

            BomStep::create([
                'bom_id'          => $bom->id,
                'step_number'     => $i + 1,
                'department_id'   => $s['department_id'] ?? null,
                'name'            => $s['name'],
                'description'     => $s['description'] ?? null,
                'estimated_hours' => $s['estimated_hours'] ?? null,
            ]);
        }
    }
}
