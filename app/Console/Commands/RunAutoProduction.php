<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;

/**
 * Kembaran tombol "Jalankan Auto Produksi" (BomController::runAuto): DUA mesin.
 *
 * Sapuan pre-order ikut terjadwal sejak 13 Okt 2026. Pemicu DP menilai keadaan sekali
 * jalan; pesanan yang terlewat (BOM auto belum aktif saat DP masuk, stok yang tadinya
 * menutup ternyata hilang) dulu hanya dievaluasi ulang kalau ada yang menekan tombol —
 * kasus TO-8-M2-2: 46 dipesan, stok 13, tanpa OP sampai batas kirim. Syarat buat/lewati
 * tetap milik PreorderAutoProductionService, jadi jadwal ini tak pernah membuat OP yang
 * tidak akan dibuat pemicu DP.
 *
 * Sejak 24 Okt 2026 dijalankan lewat Karyawan Virtual "Manager Produksi" (skill
 * produksi.op-stok-menipis & produksi.op-preorder): mode skill menentukan boleh bertindak
 * atau hanya mencatat, dan hasilnya masuk jurnal kerjanya.
 */
class RunAutoProduction extends Command
{
    protected $signature = 'production:run-auto';
    protected $description = 'Auto-produksi: BOM ready stock yang menipis + sapuan pesanan pre-order yang belum punya OP';

    public function handle(KaryawanVirtualService $kv): int
    {
        $stok    = $kv->jalankan('produksi.op-stok-menipis', KaryawanVirtualService::PEMICU_JADWAL);
        $results = $stok['hasil'];

        if (!$stok['dijalankan']) {
            $this->warn('Skill "Buat OP saat stok menipis" nonaktif — dilewati.');
        } elseif ($stok['mode'] === 'persetujuan') {
            $baru = collect($stok['usulan'])->where('baru', true)->count();
            $this->warn("Skill \"Buat OP saat stok menipis\" mode persetujuan — {$baru} usulan baru, "
                . (count($stok['usulan']) - $baru) . ' masih menunggu.');
        } elseif ($stok['mode'] !== 'otomatis') {
            $this->warn("Skill \"Buat OP saat stok menipis\" mode {$stok['mode']} — OP tidak dibuat, hanya dicatat.");
        }

        if ($stok['dijalankan'] && $results === []) {
            $this->info('Tidak ada BOM dengan auto-produksi aktif.');
        }

        foreach ($results as $r) {
            $tag = $r['created'] ? '<info>CREATED</info>' : (($r['simulasi'] ?? false) ? '<info>AKAN</info>' : '<comment>SKIPPED</comment>');
            $line = "  {$tag}  {$r['bom_number']} ({$r['bom_name']})";
            if ($r['created']) {
                $line .= " → {$r['order_number']}";
            }
            $line .= " — {$r['reason']}";
            $this->line($line);
        }

        $preorderResults = $kv->jalankan('produksi.op-preorder', KaryawanVirtualService::PEMICU_JADWAL)['hasil'];

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
