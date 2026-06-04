<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Production\Services\AutoProductionService;

class RunAutoProduction extends Command
{
    protected $signature = 'production:run-auto';
    protected $description = 'Scan all BOMs with auto_production enabled and create production orders for low-stock main products';

    public function handle(AutoProductionService $auto): int
    {
        $results = $auto->runAll();

        if ($results === []) {
            $this->info('Tidak ada BOM dengan auto-produksi aktif.');
            return self::SUCCESS;
        }

        foreach ($results as $r) {
            $tag = $r['created'] ? '<info>CREATED</info>' : '<comment>SKIPPED</comment>';
            $line = "  {$tag}  {$r['bom_number']} ({$r['bom_name']})";
            if ($r['created']) {
                $line .= " → {$r['order_number']}";
            }
            $line .= " — {$r['reason']}";
            $this->line($line);
        }

        $created = collect($results)->where('created', true)->count();
        $this->newLine();
        $this->info("Selesai. {$created} order dibuat dari " . count($results) . " BOM yang diperiksa.");

        return self::SUCCESS;
    }
}
