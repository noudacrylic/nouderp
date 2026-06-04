<?php

namespace App\Console\Commands;

use App\Modules\Production\Services\ProductionTimerSync;
use Illuminate\Console\Command;

class AutoManageProductionSessions extends Command
{
    protected $signature = 'production:auto-manage-sessions';
    protected $description = 'Auto-pause and auto-resume production steps based on each executor\'s schedule + fingerprint scan';

    public function handle(ProductionTimerSync $sync): void
    {
        $now = now();
        $summary = $sync->tick($now);

        $this->info("Tick @ {$now->format('Y-m-d H:i:s')} | paused={$summary['paused']}, resumed={$summary['resumed']}, skipped={$summary['skipped']}");
    }
}
