<?php

namespace App\Modules\Tasks\Observers;

use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Tasks\Services\TaskAutomationService;
use Illuminate\Support\Facades\Log;

class SalesOrderObserver
{
    public function created(SalesOrder $so): void
    {
        $this->runOrderAutomation($so);
    }

    public function updated(SalesOrder $so): void
    {
        // Trigger sekali lagi saat status berubah ke confirmed (kasus SO dibuat sebagai draft tanpa items)
        if ($so->wasChanged('status') && $so->status === 'confirmed') {
            $this->runOrderAutomation($so);
        }
    }

    private function runOrderAutomation(SalesOrder $so): void
    {
        try {
            app(TaskAutomationService::class)->createFromSalesOrder($so);
        } catch (\Throwable $e) {
            Log::warning('Tasks SalesOrderObserver failed', [
                'so_id' => $so->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
