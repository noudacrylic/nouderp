<?php

namespace App\Console\Commands;

use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\Product;
use App\Core\Inventory\StockLayer;
use App\Models\InventoryCostLayer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Selaraskan FIFO StockLayer dengan saldo ledger (onHand) per (produk, gudang).
 *
 * Penjualan meng-konsumsi FIFO dari `stock_layers.qty_remaining` (FifoService::consume),
 * sedangkan stok yang dilaporkan di mana-mana = `InventoryEngine::onHand` (saldo ledger).
 * Bila keduanya melenceng (data import/opening lama), penjualan gagal "Stock not enough
 * for FIFO consume" walau ledger menunjukkan stok cukup.
 *
 * Perintah ini menjadikan total qty_remaining layer = onHand:
 *   - layer kurang  → tambah 1 layer 'reconcile' di harga last_cost (createLayer: tulis StockLayer + InventoryCostLayer).
 *   - layer lebih   → kurangi qty_remaining dari layer TERBARU dulu (jaga FIFO lama) + catat InventoryCostLayer qty_out audit.
 *
 * Default DRY-RUN; pakai --apply untuk menyimpan. Idempotent (rerun → tidak ada perubahan).
 */
class ReconcileFifoLayers extends Command
{
    protected $signature = 'inventory:reconcile-fifo {--apply : Simpan perubahan (default hanya pratinjau)} {--sku= : Batasi ke satu SKU}';
    protected $description = 'Selaraskan FIFO stock layer dengan saldo ledger (onHand) agar penjualan tidak gagal FIFO consume';

    public function handle(InventoryEngine $engine): int
    {
        $apply = (bool) $this->option('apply');

        $products = Product::query()
            ->whereIn('sale_type', ['ready', 'preorder'])
            ->when($this->option('sku'), fn ($q) => $q->where('sku', $this->option('sku')))
            ->get(['id', 'sku', 'name', 'sale_type', 'last_cost', 'cost_price']);

        // Kumpulkan (product, warehouse) dari layer & ledger.
        $rows = [];
        $report = [];
        foreach ($products as $p) {
            $whIds = collect()
                ->merge(StockLayer::where('product_id', $p->id)->distinct()->pluck('warehouse_id'))
                ->merge(\App\Models\InventoryLedger::where('product_id', $p->id)->distinct()->pluck('warehouse_id'))
                ->filter()->unique()->values();

            foreach ($whIds as $whId) {
                $onHand = round((float) $engine->onHand($p->id, $whId), 4);
                $target = max(0, $onHand); // layer tidak bisa negatif
                $current = round((float) StockLayer::where('product_id', $p->id)->where('warehouse_id', $whId)->sum('qty_remaining'), 4);
                $diff = round($target - $current, 4);
                if (abs($diff) < 0.0001) continue;

                $report[] = [$p->sku, $whId, $current, $onHand, $diff];
                $rows[] = compact('p', 'whId', 'target', 'current', 'diff');
            }
        }

        if (empty($rows)) {
            $this->info('Semua FIFO layer sudah selaras dengan ledger. Tidak ada yang perlu diperbaiki.');
            return self::SUCCESS;
        }

        $this->table(['SKU', 'WH', 'FIFO sekarang', 'onHand (target)', 'Selisih'], $report);

        if (!$apply) {
            $this->warn(count($rows) . ' baris perlu reconcile. Ini DRY-RUN — jalankan ulang dengan --apply untuk menyimpan.');
            return self::SUCCESS;
        }

        $fifo = app(\App\Core\Inventory\FifoService::class);
        DB::transaction(function () use ($rows, $fifo) {
            foreach ($rows as $r) {
                $p = $r['p']; $whId = $r['whId']; $diff = $r['diff'];
                if ($diff > 0) {
                    // Kurang → tambah layer make-up di last_cost.
                    $cost = (float) ($p->last_cost ?: $p->cost_price ?: 0);
                    $fifo->createLayer($p->id, $whId, $diff, $cost, 'reconcile', 0);
                } else {
                    // Lebih → kurangi dari layer TERBARU dulu.
                    $remove = -$diff;
                    $layers = StockLayer::where('product_id', $p->id)->where('warehouse_id', $whId)
                        ->where('qty_remaining', '>', 0)->orderByDesc('id')->lockForUpdate()->get();
                    foreach ($layers as $layer) {
                        if ($remove <= 0) break;
                        $take = min((float) $layer->qty_remaining, $remove);
                        $layer->qty_remaining = (float) $layer->qty_remaining - $take;
                        $layer->save();
                        InventoryCostLayer::create([
                            'product_id' => $p->id, 'qty_out' => $take, 'unit_cost' => $layer->unit_cost,
                            'reference_type' => 'reconcile', 'reference_id' => 0,
                        ]);
                        $remove -= $take;
                    }
                }
            }
        });

        $this->info(count($rows) . ' baris ter-reconcile. FIFO layer kini = onHand.');
        return self::SUCCESS;
    }
}
