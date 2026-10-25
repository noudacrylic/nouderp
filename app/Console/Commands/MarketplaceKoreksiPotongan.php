<?php

namespace App\Console\Commands;

use App\Models\MarketplaceOrderFee;
use App\Models\SalesInvoice;
use App\Modules\Sales\Services\MarketplaceEngineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Susulkan koreksi potongan marketplace untuk faktur yang SUDAH dicairkan sebelum rincian
 * finalnya lengkap (lihat MarketplaceEngineService::koreksiKeRincianFinal).
 *
 * LATAR. Diagnosis 26 Okt 2026: Saldo Penjualan Shopee di ERP lebih besar dari Saldo Penjual
 * asli karena dana masuk per pesanan dijurnal sebelum affiliate & premi pengiriman dipotong.
 * Sejak perbaikan ini koreksinya otomatis setiap rincian final tersimpan; command ini
 * membereskan yang terlanjur. Hanya faktur yang punya rincian final dari Jubelio — sisanya
 * (tanpa rincian) diselesaikan lewat unggah Rekonsiliasi Marketplace.
 *
 * Aman diulang: faktur yang sudah sama tidak dijurnal lagi.
 */
class MarketplaceKoreksiPotongan extends Command
{
    protected $signature = 'marketplace:koreksi-potongan
        {--dry-run : Hitung saja, tanpa menulis apa pun}
        {--customer= : Batasi ke satu customer marketplace (id)}
        {--limit= : Batasi jumlah faktur yang diperiksa}';

    protected $description = 'Samakan dana masuk wallet faktur marketplace yang sudah cair dengan saldo masuk final';

    public function handle(MarketplaceEngineService $engine): int
    {
        $dry = (bool) $this->option('dry-run');

        $q = SalesInvoice::query()
            ->where('status', '!=', 'void')
            ->where('marketplace_processed', true)
            ->whereExists(fn ($w) => $w->select(DB::raw(1))->from('marketplace_order_fees as f')
                ->whereColumn('f.sales_order_id', 'sales_invoices.sales_order_id')
                ->where('f.final', true)->where('f.sumber', 'jubelio'))
            ->orderBy('id');
        if ($c = $this->option('customer')) {
            $q->where('customer_id', (int) $c);
        }
        if ($limit = (int) $this->option('limit')) {
            $q->limit($limit);
        }

        $total = $q->count();
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Memeriksa {$total} faktur marketplace berincian final.");

        $dikoreksi = 0; $nilai = 0.0; $gagal = 0; $perBulan = [];
        $bar = $this->output->createProgressBar($total);

        $q->chunkById(200, function ($invoices) use ($engine, $dry, &$dikoreksi, &$nilai, &$gagal, &$perBulan, $bar) {
            $rincian = MarketplaceOrderFee::whereIn('sales_order_id', $invoices->pluck('sales_order_id'))
                ->get()->keyBy('sales_order_id');

            foreach ($invoices as $inv) {
                $bar->advance();
                $r = $rincian->get($inv->sales_order_id);
                if (!$r) {
                    continue;
                }

                DB::beginTransaction();
                try {
                    $sebelum = (float) $inv->mp_fee_koreksi;
                    $j = $engine->koreksiKeRincianFinal($inv, $r);
                    if ($j) {
                        // Positif = wallet diturunkan (potongan susulan), negatif = dinaikkan.
                        $selisih = round((float) $inv->mp_fee_koreksi - $sebelum, 2);
                        $dikoreksi++;
                        $nilai += $selisih;
                        $bulan = substr((string) $j->date, 0, 7);
                        $perBulan[$bulan] = ($perBulan[$bulan] ?? 0) + $selisih;
                    }
                    $dry ? DB::rollBack() : DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $gagal++;
                    $this->newLine();
                    $this->warn("  • {$inv->invoice_number}: gagal — {$e->getMessage()}");
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        ksort($perBulan);
        foreach ($perBulan as $bulan => $v) {
            $this->line(sprintf('  %s  %s', $bulan, number_format($v, 0, ',', '.')));
        }
        $this->info(sprintf('%sDikoreksi %d faktur, total wallet turun Rp %s. Gagal: %d.',
            $dry ? '[DRY-RUN] Akan ' : '', $dikoreksi, number_format($nilai, 0, ',', '.'), $gagal));

        return self::SUCCESS;
    }
}
