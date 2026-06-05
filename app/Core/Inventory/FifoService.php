<?php

namespace App\Core\Inventory;

use App\Core\Inventory\StockLayer;
use App\Models\InventoryCostLayer;
use Exception;

class FifoService
{
    public function createLayer($productId, $warehouseId, $qty, $cost, $source, $referenceId = 0)
    {
        // Record to New Standard InventoryCostLayer
        InventoryCostLayer::create([
            'product_id' => $productId,
            'qty_in' => $qty,
            'qty_balance' => $qty,
            'unit_cost' => $cost,
            'reference_type' => $source,
            'reference_id' => $referenceId
        ]);

        return StockLayer::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'qty_in' => $qty,
            'qty_remaining' => $qty,
            'unit_cost' => $cost,
            'source_type' => $source,
            'source_id' => $referenceId
        ]);
    }

    public function consume($productId, $warehouseId, $qty, $referenceType = 'consume', $referenceId = 0)
    {
        $remaining = $qty;
        $totalCost = 0;

        $layers = StockLayer::where([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId
        ])
            ->where('qty_remaining', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($remaining <= 0.00001) {
                break;
            }

            /** @var StockLayer $layer */
            $take = min((float) $layer->qty_remaining, (float) $remaining);

            // Using direct model update to avoid decrement() on generic object issues
            $layer->qty_remaining = (float) $layer->qty_remaining - $take;
            $layer->save();

            // Record consumption to New Standard InventoryCostLayer
            InventoryCostLayer::create([
                'product_id' => $productId,
                'qty_out' => $take,
                'unit_cost' => $layer->unit_cost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId
            ]);

            $totalCost += $take * (float) $layer->unit_cost;
            $remaining -= $take;
        }

        // Toleransi epsilon: sisa float micro-positif bukan kekurangan stok asli.
        if ($remaining > 0.00001) {
            throw new Exception('Stock not enough for FIFO consume');
        }

        return $totalCost;
    }

    public function moveLayer($productId, $fromWarehouseId, $toWarehouseId, $qty)
    {
        $remaining = $qty;

        $layers = StockLayer::where([
            'product_id' => $productId,
            'warehouse_id' => $fromWarehouseId
        ])
            ->where('qty_remaining', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($remaining <= 0.00001) {
                break;
            }

            /** @var StockLayer $layer */
            $take = min((float) $layer->qty_remaining, (float) $remaining);

            // 1. Credit source layer
            $layer->qty_remaining = (float) $layer->qty_remaining - $take;
            $layer->save();

            // 2. Debit target layer (New layer at destination)
            StockLayer::create([
                'product_id' => $productId,
                'warehouse_id' => $toWarehouseId,
                'source_type' => 'transfer',
                'qty_in' => $take,
                'qty_remaining' => $take,
                'unit_cost' => $layer->unit_cost
            ]);

            $remaining -= $take;
        }

        // Toleransi epsilon: sisa float micro-positif bukan kekurangan stok asli.
        if ($remaining > 0.00001) {
            throw new Exception('Stock not enough for FIFO move');
        }
    }

    public function stockIn($productId, $warehouseId, $type, $reference, $qty, $cost, $transactionId = 0)
    {
        $engine = app(\App\Core\Inventory\InventoryEngine::class);

        // 1. Record Ledger
        $engine->ledger(
            productId: $productId,
            warehouseId: $warehouseId,
            qtyIn: $qty,
            qtyOut: 0,
            type: $type,
            reference: $reference,
            transactionId: $transactionId
        );

        // 2. Record FIFO Layer
        $this->createLayer($productId, $warehouseId, $qty, $cost, strtolower($type), $transactionId);
    }

    public function updateOpeningLayer($productId, $warehouseId, $qty, $cost, $transactionId)
    {
        // 1. Update StockLayer
        $layer = StockLayer::where([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'source_type' => 'opening',
            'source_id' => $transactionId
        ])->first();

        if ($layer) {
            $layer->qty_in = $qty;
            $layer->qty_remaining = $qty;
            $layer->unit_cost = $cost;
            $layer->save();
        }

        // 2. Update InventoryCostLayer
        $costLayer = InventoryCostLayer::where([
            'product_id' => $productId,
            'reference_type' => 'opening',
            'reference_id' => $transactionId
        ])->first();

        if ($costLayer) {
            $costLayer->qty_in = $qty;
            $costLayer->qty_balance = $qty;
            $costLayer->unit_cost = $cost;
            $costLayer->save();
        }
    }
}
