<?php

namespace App\Modules\SDM\Observers;

use App\Modules\Production\Services\ProductionTimerSync;
use App\Modules\SDM\Models\FingerprintLog;
use Illuminate\Support\Facades\Log;

class FingerprintLogObserver
{
    public function created(FingerprintLog $log): void
    {
        if (!$log->karyawan_id) return;

        try {
            app(ProductionTimerSync::class)->onFingerprintScan($log);
        } catch (\Throwable $e) {
            Log::warning('ProductionTimerSync failed on fingerprint scan', [
                'log_id'      => $log->id,
                'karyawan_id' => $log->karyawan_id,
                'verify_type' => $log->verify_type,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
