<?php

namespace App\Modules\Tasks\Observers;

use App\Core\Inventory\StockMovement;
use App\Modules\Tasks\Services\TaskAutomationService;
use Illuminate\Support\Facades\Log;

class StockMovementObserver
{
    public function created(StockMovement $movement): void
    {
        if (!$movement->product_id) return;

        try {
            app(TaskAutomationService::class)->handleStockChange((int) $movement->product_id);
        } catch (\Throwable $e) {
            Log::warning('Tasks StockMovementObserver failed', [
                'movement_id' => $movement->id,
                'product_id'  => $movement->product_id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
