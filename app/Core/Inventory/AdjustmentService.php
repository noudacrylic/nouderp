<?php

namespace App\Core\Inventory;

use Illuminate\Support\Facades\DB;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAccountSetting;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\FifoService;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use Exception;

class AdjustmentService
{
    public function __construct(
        protected InventoryEngine $engine,
        protected FifoService $fifo,
        protected JournalPostingService $journalService
    ) {
    }

    public function post(int $adjustmentId): void
    {
        DB::transaction(function () use ($adjustmentId) {

            $adj = InventoryAdjustment::with('items.product')->findOrFail($adjustmentId);

            if ($adj->status !== 'draft') {
                throw new Exception("Already posted.");
            }

            $setting = InventoryAccountSetting::first();
            if (!$setting) {
                throw new Exception("Inventory account settings not found. Please setup adjustment accounts first.");
            }

            $totalGainPerAssetAccount = [];
            $totalLossPerAssetAccount = [];
            $totalGainOverall = 0;
            $totalLossOverall = 0;

            foreach ($adj->items as $item) {
                $product = $item->product;
                $diff = $item->actual_qty - $item->system_qty;

                if ($diff == 0)
                    continue;

                $inventoryAccountId = $product->inventory_account_id ?? $setting->inventory_asset_account_id;

                if ($diff > 0) {
                    // STOCK PLUS (GAIN)
                    $this->engine->ledger(
                        productId: $product->id,
                        warehouseId: $adj->warehouse_id,
                        qtyIn: $diff,
                        qtyOut: 0,
                        type: 'ADJUSTMENT_IN',
                        reference: $adj->number,
                        transactionId: $adj->id
                    );

                    $cost = $product->cost_price ?? 0;
                    $this->fifo->createLayer(
                        productId: $product->id,
                        warehouseId: $adj->warehouse_id,
                        qty: $diff,
                        cost: $cost,
                        source: 'adjustment',
                        referenceId: $adj->id
                    );

                    $value = $diff * $cost;
                    $totalGainPerAssetAccount[$inventoryAccountId] = ($totalGainPerAssetAccount[$inventoryAccountId] ?? 0) + $value;
                    $totalGainOverall += $value;
                } else {
                    // STOCK MINUS (LOSS)
                    $qtyOut = abs($diff);
                    $this->engine->ledger(
                        productId: $product->id,
                        warehouseId: $adj->warehouse_id,
                        qtyIn: 0,
                        qtyOut: $qtyOut,
                        type: 'ADJUSTMENT_OUT',
                        reference: $adj->number,
                        transactionId: $adj->id
                    );

                    $totalCost = $this->fifo->consume(
                        productId: $product->id,
                        warehouseId: $adj->warehouse_id,
                        qty: $qtyOut,
                        referenceType: 'adjustment',
                        referenceId: $adj->id
                    );

                    $totalLossPerAssetAccount[$inventoryAccountId] = ($totalLossPerAssetAccount[$inventoryAccountId] ?? 0) + $totalCost;
                    $totalLossOverall += $totalCost;
                }
            }

            // Create ONE Balanced Journal for the whole Adjustment
            if ($totalGainOverall > 0 || $totalLossOverall > 0) {
                $lines = [];

                // For Gains: Dr Inventory Asset, Cr Gain Account
                if ($totalGainOverall > 0) {
                    foreach ($totalGainPerAssetAccount as $accountId => $amount) {
                        $lines[] = new JournalLineDTO(account_id: $accountId, debit: $amount, credit: 0, description: "Inventory Gain");
                    }
                    $lines[] = new JournalLineDTO(account_id: $setting->inventory_gain_account_id, debit: 0, credit: $totalGainOverall, description: "Inventory Gain");
                }

                // For Losses: Dr Loss Account, Cr Inventory Asset
                if ($totalLossOverall > 0) {
                    $lines[] = new JournalLineDTO(account_id: $setting->inventory_loss_account_id, debit: $totalLossOverall, credit: 0, description: "Inventory Loss");
                    foreach ($totalLossPerAssetAccount as $accountId => $amount) {
                        $lines[] = new JournalLineDTO(account_id: $accountId, debit: 0, credit: $amount, description: "Inventory Loss");
                    }
                }

                $this->journalService->post(new JournalEntryDTO(
                    date: $adj->date,
                    reference_type: 'inventory_adjustment',
                    reference_id: $adj->id,
                    description: "Adjustment: {$adj->number}",
                    lines: $lines,
                    reference_number: $adj->number
                ));
            }

            $adj->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => auth()->user()?->name ?? 'System'
            ]);
        });
    }
}
