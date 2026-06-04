<?php

namespace App\Core\Inventory;

use Illuminate\Support\Facades\DB;
use App\Models\InventoryTransfer;
use App\Core\Inventory\InventoryEngine;

class TransferService
{
    public function post(int $transferId): void
    {
        DB::transaction(function () use ($transferId) {

            $transfer = InventoryTransfer::with('items')
                ->findOrFail($transferId);

            if ($transfer->status !== 'draft') {
                throw new \Exception("Transfer already posted.");
            }

            $engine = app(InventoryEngine::class);

            foreach ($transfer->items as $item) {
                // Perform the transfer using the unified engine
                // Using 'number' as the reference
                $engine->transfer(
                    $item->product_id,
                    $transfer->from_warehouse_id,
                    $transfer->to_warehouse_id,
                    $item->qty,
                    $transfer->number,
                    $transfer->id
                );
            }

            $transfer->update([
                'status' => 'posted'
            ]);
        });
    }
}
