<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Production\Services\AutoProductionService;
use App\Modules\Production\Services\PreorderAutoProductionService;

/**
 * Kembaran tombol "Jalankan Auto Produksi" (BomController::runAuto): DUA mesin.
 *
 * Sapuan pre-order ikut terjadwal sejak 13 Okt 2026. Pemicu DP menilai keadaan sekali
 * jalan; pesanan yang terlewat (BOM auto belum aktif saat DP masuk, stok yang tadinya
 * menutup ternyata hilang) dulu hanya dievaluasi ulang kalau ada yang menekan tombol —
 * kasus TO-8-M2-2: 46 dipesan, stok 13, tanpa OP sampai batas kirim. Syarat buat/lewati
 * tetap milik PreorderAutoProductionService, jadi jadwal ini tak pernah membuat OP yang
 * tidak akan dibuat pemicu DP.
 */
class RunAutoProduction extends Command
{
    protected $signature = 'production:run-auto';
    protected $description = 'Auto-produksi: BOM ready stock yang menipis + sapuan pesanan pre-order yang belum punya OP';

    public function handle(AutoProductionService $auto, PreorderAutoProductionService $preorder): int
    {
        $results = $auto->runAll();

        if ($results === []) {
            $this->info('Tidak ada BOM dengan auto-produksi aktif.');
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

        $preorderResults = $preorder->sweepPendingSalesOrders();

        foreach ($preorderResults as $r) {
            if ($r['created'] || ! str_contains((string) $r['reason'], 'Bukan produk preorder')) {
                $tag = $r['created'] ? '<info>CREATED</info>' : '<comment>SKIPPED</comment>';
                $this->line("  {$tag}  pre-order {$r['sales_order_number']}"
                    . ($r['created'] ? " → {$r['order_number']}" : '') . " — {$r['reason']}");
            }
        }

        $created = collect($results)->where('created', true)->count()
            + collect($preorderResults)->where('created', true)->count();
        $this->newLine();
        $this->info("Selesai. {$created} order dibuat dari " . count($results) . ' BOM stok + '
            . collect($preorderResults)->pluck('sales_order_id')->unique()->count() . ' pesanan pre-order yang diperiksa.');

        return self::SUCCESS;
    }
}
