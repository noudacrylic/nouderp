<?php

namespace App\Modules\Production\Observers;

use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Production\Services\PreorderAutoProductionService;
use Illuminate\Support\Facades\Log;

/**
 * Pemicu universal auto-produksi preorder: BEGITU sebuah Sales Advance (DP/uang muka)
 * berstatus "posted", buat Production Order preorder untuk SO terkait — tidak peduli
 * DP-nya dari pembayaran manual, payment link, ATAU marketplace/Midtrans.
 *
 * PreorderAutoProductionService::runForSalesOrder() bersifat idempotent (skip kalau OP
 * sudah ada) sehingga aman walau terpicu lebih dari sekali untuk SO yang sama.
 */
class SalesAdvanceObserver
{
    public function created(SalesAdvance $advance): void
    {
        if ($advance->status === 'posted') {
            $this->triggerProduction($advance);
        }
    }

    public function updated(SalesAdvance $advance): void
    {
        if ($advance->status === 'posted' && $advance->wasChanged('status')) {
            $this->triggerProduction($advance);
        }
    }

    protected function triggerProduction(SalesAdvance $advance): void
    {
        if (!$advance->sales_order_id) {
            return;
        }

        // Best-effort: kegagalan auto-produksi TIDAK boleh membatalkan posting DP.
        try {
            $so = SalesOrder::with(['items.product', 'customer'])->find($advance->sales_order_id);
            if ($so) {
                app(PreorderAutoProductionService::class)->runForSalesOrder($so);
            }
        } catch (\Throwable $e) {
            Log::error('PreorderAutoProduction (via SalesAdvance observer) gagal', [
                'sales_advance_id' => $advance->id,
                'sales_order_id'   => $advance->sales_order_id,
                'error'            => $e->getMessage(),
            ]);
        }
    }
}
