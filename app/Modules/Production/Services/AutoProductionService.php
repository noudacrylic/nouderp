<?php

namespace App\Modules\Production\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoProductionService
{
    public function __construct(
        private BomService $bomService,
        private ProductionOrderService $orderService,
    ) {}

    /**
     * Scan semua BOM dengan auto_production = true, dan buatkan order produksi
     * untuk yang produk utamanya sedang menipis dan belum punya produksi aktif.
     *
     * Return: list result per BOM untuk reporting/logging.
     *   [['bom_id' => X, 'bom_number' => 'BOM/...', 'created' => bool, 'order_number' => 'OP/...', 'reason' => '...'], ...]
     */
    public function runAll(): array
    {
        $boms = Bom::with(['outputs.product'])
            ->where('auto_production', true)
            ->get();

        $results = [];

        foreach ($boms as $bom) {
            $results[] = $this->runForBom($bom);
        }

        return $results;
    }

    /**
     * Evaluasi 1 BOM: jika low stock + tidak ada produksi aktif, buat order otomatis.
     */
    public function runForBom(Bom $bom): array
    {
        $base = [
            'bom_id'     => $bom->id,
            'bom_number' => $bom->bom_number,
            'bom_name'   => $bom->name,
            'created'    => false,
            'order_number' => null,
            'reason'     => null,
        ];

        if (!$bom->auto_production) {
            return array_merge($base, ['reason' => 'Auto-produksi tidak aktif untuk BOM ini.']);
        }

        $bom->loadMissing('outputs.product');
        $mainOutput = $bom->outputs->firstWhere('output_type', 'main');

        if (!$mainOutput || !$mainOutput->product) {
            return array_merge($base, ['reason' => 'BOM tidak memiliki output utama dengan produk valid.']);
        }

        $product = $mainOutput->product;

        // Penentu ready vs preorder = sale_type produk utama. Produk preorder TIDAK
        // diproduksi berdasarkan stok menipis — OP-nya hanya dibuat saat ada pesanan
        // (DP di-post) via PreorderAutoProductionService. Scanner stok ini khusus produk ready.
        if ($product->sale_type === 'preorder') {
            return array_merge($base, ['reason' => "Produk {$product->sku} preorder — OP dibuat saat ada pesanan (DP), bukan dari stok menipis."]);
        }

        $qtyOnHand = (float) ProductStock::where('product_id', $product->id)->sum('qty_on_hand');
        $minStock  = $product->min_stock !== null ? (float) $product->min_stock : null;

        // Trigger jika: stok habis/minus (apa pun nilai min_stock), atau menipis (qty <= min_stock yang valid).
        $isEmpty   = $qtyOnHand <= 0;
        $isLow     = $minStock !== null && $minStock > 0 && $qtyOnHand <= $minStock;

        if (!$isEmpty && !$isLow) {
            $minLabel = $minStock !== null && $minStock > 0
                ? "min {$minStock}"
                : "min_stock belum di-set";
            return array_merge($base, ['reason' => "Stok {$product->sku} ({$qtyOnHand}) masih aman ({$minLabel})."]);
        }

        // Anti-duplikat: cek produksi aktif untuk produk utama yang sama (apa pun sumbernya).
        $hasActive = ProductionOrder::whereNotIn('status', ['finalized', 'cancelled'])
            ->whereHas('outputs', function ($q) use ($product) {
                $q->where('product_id', $product->id)
                  ->where('output_type', 'main');
            })
            ->exists();

        if ($hasActive) {
            return array_merge($base, ['reason' => "Sudah ada produksi aktif untuk produk {$product->sku}."]);
        }

        $warehouse = Warehouse::query()
            ->where('is_active', 1)
            ->orderByRaw("CASE WHEN LOWER(name) LIKE '%utama%' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if (!$warehouse) {
            return array_merge($base, ['reason' => 'Tidak ada gudang aktif untuk dipakai.']);
        }

        $cycles = (int) max(1, (int) ($bom->typical_cycles ?? 1));

        $materials = $this->bomService->calculateMaterials($bom->id, $cycles);
        $outputs   = $this->bomService->calculateOutputs($bom->id, $cycles);
        $steps     = $this->bomService->getSteps($bom->id);

        $triggerLabel = match(true) {
            $qtyOnHand < 0  => "stok minus {$qtyOnHand}",
            $qtyOnHand == 0 => 'stok habis',
            default         => "stok {$qtyOnHand} <= min {$minStock}",
        };

        try {
            $order = DB::transaction(function () use ($bom, $cycles, $warehouse, $materials, $outputs, $steps, $product, $triggerLabel) {
                return $this->orderService->create([
                    'type'            => 'ready_stock',
                    'bom_id'          => $bom->id,
                    'warehouse_id'    => $warehouse->id,
                    'production_date' => now()->format('Y-m-d'),
                    'planned_cycles'  => $cycles,
                    'score_type'      => 'auto',
                    'created_via'     => 'auto',
                    'description'     => $bom->description,
                    'notes'           => "Order otomatis dari BOM {$bom->bom_number} ({$product->sku}: {$triggerLabel}).",
                    'materials'       => array_map(fn($m) => [
                        'product_id'   => $m['product_id'],
                        'qty_required' => $m['qty_required'],
                        'unit'         => $m['unit'] ?? null,
                    ], $materials),
                    'outputs' => array_map(fn($o) => [
                        'product_id'  => $o['product_id'],
                        'qty_planned' => $o['qty_planned'],
                        'output_type' => $o['output_type'],
                        'percentage'  => $o['percentage'],
                    ], $outputs),
                    'steps' => $steps,
                ]);
            });

            // Langsung konfirmasi: consume material dari stok + jurnal WIP, status menjadi 'confirmed'
            // sehingga operator bisa langsung mulai. Kalau material tidak cukup, order tetap draft.
            try {
                $this->orderService->confirm($order->id);
                $statusNote = 'dikonfirmasi, siap dikerjakan';
            } catch (\Throwable $confirmError) {
                Log::warning('AutoProductionService: order dibuat tapi gagal dikonfirmasi otomatis', [
                    'order_id' => $order->id,
                    'message'  => $confirmError->getMessage(),
                ]);
                $statusNote = 'tetap draft (konfirmasi otomatis gagal: ' . $confirmError->getMessage() . ')';
            }

            return array_merge($base, [
                'created'      => true,
                'order_number' => $order->order_number,
                'reason'       => "Order produksi dibuat: {$cycles} siklus ({$triggerLabel}) — {$statusNote}.",
            ]);
        } catch (\Throwable $e) {
            Log::error('AutoProductionService gagal membuat order', [
                'bom_id'  => $bom->id,
                'message' => $e->getMessage(),
            ]);
            return array_merge($base, ['reason' => 'Gagal membuat order: ' . $e->getMessage()]);
        }
    }
}
