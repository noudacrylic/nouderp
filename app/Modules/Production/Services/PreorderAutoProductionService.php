<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PreorderAutoProductionService
{
    public function __construct(
        private BomService $bomService,
        private ProductionOrderService $orderService,
    ) {}

    /**
     * Untuk tiap item SO yang produk-nya pre-order: cari BOM auto, buat order produksi
     * dengan planned_cycles = qty item, lalu auto-confirm.
     *
     * Berbeda dengan AutoProductionService (ready stock):
     * - Trigger reaktif (saat SO confirmed), bukan scan periodik
     * - Tidak ada cek skip-if-running — tiap pesanan customer berdiri sendiri
     * - planned_cycles dipetakan 1:1 dari qty SO item (BOM wajib qty_per_cycle=1)
     *
     * Return: list result per item untuk reporting/logging.
     */
    public function runForSalesOrder(SalesOrder $so): array
    {
        $so->loadMissing(['items.product', 'customer']);

        $results = [];
        foreach ($so->items as $idx => $item) {
            $results[] = $this->runForItem($so, $item, $idx + 1);
        }

        return $results;
    }

    private function runForItem(SalesOrder $so, SalesOrderItem $item, int $lineNo): array
    {
        $base = [
            'sales_order_id'    => $so->id,
            'sales_order_item_id' => $item->id,
            'line_no'           => $lineNo,
            'product_id'        => $item->product_id,
            'created'           => false,
            'order_number'      => null,
            'reason'             => null,
        ];

        $product = $item->product;
        if (!$product) {
            return array_merge($base, ['reason' => 'Produk tidak ditemukan.']);
        }

        if ($product->sale_type !== 'preorder') {
            return array_merge($base, ['reason' => 'Bukan produk preorder, dilewati.']);
        }

        $bom = Bom::with('outputs')
            ->where('auto_production', true)
            ->whereHas('outputs', fn($q) => $q->where('output_type', 'main')
                                              ->where('product_id', $product->id))
            ->first();

        if (!$bom) {
            Log::warning('PreorderAutoProduction: BOM auto tidak ditemukan untuk produk preorder', [
                'sales_order_id' => $so->id,
                'product_id'     => $product->id,
                'product_sku'    => $product->sku,
            ]);
            return array_merge($base, ['reason' => "BOM auto tidak ditemukan untuk produk {$product->sku}."]);
        }

        $mainOutput = $bom->outputs->firstWhere('output_type', 'main');
        if (!$mainOutput || abs((float) $mainOutput->qty_per_cycle - 1.0) > 0.0001) {
            Log::error('PreorderAutoProduction: BOM auto preorder dengan qty_per_cycle != 1 (data tidak konsisten)', [
                'bom_id'         => $bom->id,
                'bom_number'     => $bom->bom_number,
                'qty_per_cycle'  => $mainOutput?->qty_per_cycle,
                'sales_order_id' => $so->id,
            ]);
            return array_merge($base, ['reason' => "BOM {$bom->bom_number} tidak valid untuk preorder (qty per siklus harus = 1)."]);
        }

        // Idempotent: kalau OP auto-preorder untuk SO + produk ini sudah ada, jangan dobel.
        // (Trigger dari DP bisa terjadi >1 kali — DP pertama, DP kedua, dst.)
        $alreadyExists = ProductionOrder::where('sales_order_id', $so->id)
            ->where('created_via', 'auto_preorder')
            ->whereHas('outputs', fn($q) => $q->where('output_type', 'main')
                                              ->where('product_id', $product->id))
            ->exists();
        if ($alreadyExists) {
            return array_merge($base, ['reason' => 'Order produksi auto sudah ada untuk produk ini, dilewati.']);
        }

        $cycles = (int) max(1, (int) ($item->qty * ($item->conversion_to_base ?? 1)));

        $materials = $this->bomService->calculateMaterials($bom->id, $cycles);
        $outputs   = $this->bomService->calculateOutputs($bom->id, $cycles);
        $steps     = $this->bomService->getSteps($bom->id);

        $customerName = $so->customer?->name ?? '-';
        $notes = "Auto-produksi pre-order dari SO {$so->order_number} (Customer: {$customerName}, item baris #{$lineNo}).";

        try {
            $order = DB::transaction(function () use ($bom, $so, $cycles, $materials, $outputs, $steps, $notes) {
                return $this->orderService->create([
                    'type'            => 'custom',
                    'bom_id'          => $bom->id,
                    'sales_order_id'  => $so->id,
                    'warehouse_id'    => $so->warehouse_id,
                    'production_date' => now()->format('Y-m-d'),
                    'planned_cycles'  => $cycles,
                    'score_type'      => 'auto',
                    'created_via'     => 'auto_preorder',
                    'description'     => $bom->description,
                    'notes'           => $notes,
                    'materials' => array_map(fn($m) => [
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

            // Soft-confirm: preorder langsung 'confirmed' (siap dikerjakan) TANPA konsumsi
            // material sekarang — material biasanya belum dibeli saat DP masuk. Konsumsi FIFO +
            // jurnal WIP dilakukan nanti saat finalisasi produksi (konsumsi tertunda).
            $order->update(['status' => 'confirmed']);
            $statusNote = 'dikonfirmasi (material dikonsumsi saat finalisasi)';

            return array_merge($base, [
                'created'      => true,
                'order_number' => $order->order_number,
                'reason'       => "Order produksi dibuat: {$cycles} siklus — {$statusNote}.",
            ]);
        } catch (\Throwable $e) {
            Log::error('PreorderAutoProduction: gagal membuat order', [
                'sales_order_id' => $so->id,
                'product_id'     => $product->id,
                'bom_id'         => $bom->id,
                'message'        => $e->getMessage(),
            ]);
            return array_merge($base, ['reason' => 'Gagal membuat order: ' . $e->getMessage()]);
        }
    }
}
