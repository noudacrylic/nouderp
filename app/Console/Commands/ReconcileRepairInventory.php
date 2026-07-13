<?php

namespace App\Console\Commands;

use App\Core\Accounting\Account;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\JournalLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rekonsiliasi akun 1131 "Persediaan Perbaikan" terhadap nilai fisik Gudang Perbaikan.
 *
 * Sejak overhaul OP Perbaikan berbasis SKU, Gudang Perbaikan (is_repair=1) menjadi wujud
 * fisik akun 1131: retur kondisi 'repair' & penyesuaian 'perbaikan_rusak' masuk ke sini,
 * OP tipe 'perbaikan' mengambil darinya. Command ini membandingkan:
 *   • Saldo GL 1131 (debit − kredit dari journal_lines yang tidak void)
 *   • Nilai FIFO stok di Gudang Perbaikan (Σ qty_remaining × unit_cost)
 *
 * Selisih WAJAR bila masih ada: barang garansi (warranty) yang men-debit 1131 tapi
 * secara fisik dititip di gudang jual, serta OP 'repair' legacy (data lama sebelum
 * pemisahan). REPORT-ONLY: tidak mengubah data — untuk audit saat/sesudah deploy.
 */
class ReconcileRepairInventory extends Command
{
    protected $signature = 'production:reconcile-repair-inventory';

    protected $description = 'Bandingkan saldo GL 1131 Persediaan Perbaikan vs nilai fisik Gudang Perbaikan (report-only)';

    public function handle(): int
    {
        $account = Account::where('code', \App\Enums\AccountCodeEnum::INVENTORY_REPAIR)->first();
        if (!$account) {
            $this->error('Akun 1131 (INVENTORY_REPAIR) tidak ditemukan.');
            return self::FAILURE;
        }

        $repairWh = Warehouse::repair();
        if (!$repairWh) {
            $this->error('Gudang Perbaikan (is_repair=1) belum ada. Jalankan migrasi dulu.');
            return self::FAILURE;
        }

        // Saldo GL 1131 = Σ debit − Σ kredit dari baris jurnal non-void.
        $glBalance = (float) JournalLine::where('account_id', $account->id)
            ->whereHas('journal', fn ($q) => $q->where('status', '!=', 'void'))
            ->sum(DB::raw('debit - credit'));

        // Nilai fisik Gudang Perbaikan = Σ qty_remaining × unit_cost (FIFO).
        $layers = StockLayer::where('warehouse_id', $repairWh->id)
            ->where('qty_remaining', '>', 0)
            ->get();
        $physicalValue = (float) $layers->sum(fn ($l) => (float) $l->qty_remaining * (float) $l->unit_cost);
        $physicalQty   = (float) $layers->sum('qty_remaining');

        $diff = round($glBalance - $physicalValue, 2);

        $this->info('── Rekonsiliasi Persediaan Perbaikan (1131) ──');
        $this->table(['Komponen', 'Nilai'], [
            ['Saldo GL 1131 (buku besar)',        'Rp ' . number_format($glBalance, 2, ',', '.')],
            ['Nilai fisik Gudang Perbaikan (FIFO)', 'Rp ' . number_format($physicalValue, 2, ',', '.')],
            ['Qty fisik di Gudang Perbaikan',      rtrim(rtrim(number_format($physicalQty, 4, ',', '.'), '0'), ',')],
            ['SELISIH (GL − fisik)',               'Rp ' . number_format($diff, 2, ',', '.')],
        ]);

        if (abs($diff) < 0.01) {
            $this->info('✓ Cocok. GL 1131 = nilai fisik Gudang Perbaikan.');
        } else {
            $this->warn('Selisih ' . number_format($diff, 2, ',', '.') . '. Sumber wajar: barang garansi (debit 1131 tapi fisik di gudang jual) & OP "repair" legacy sebelum pemisahan gudang. Periksa bila di luar dugaan.');

            // Rincian SKU di Gudang Perbaikan sebagai bantuan audit.
            $byProduct = $layers->groupBy('product_id')->map(fn ($rows) => [
                'qty'   => (float) $rows->sum('qty_remaining'),
                'value' => (float) $rows->sum(fn ($l) => (float) $l->qty_remaining * (float) $l->unit_cost),
            ]);
            if ($byProduct->isNotEmpty()) {
                $this->line('');
                $this->line('Isi Gudang Perbaikan per SKU:');
                $rows = [];
                foreach ($byProduct as $pid => $d) {
                    $p = \App\Core\Inventory\Product::find($pid);
                    $rows[] = [
                        $p?->sku ?? $pid,
                        $p?->name ?? '—',
                        rtrim(rtrim(number_format($d['qty'], 4, ',', '.'), '0'), ','),
                        'Rp ' . number_format($d['value'], 2, ',', '.'),
                    ];
                }
                $this->table(['SKU', 'Nama', 'Qty', 'Nilai'], $rows);
            }
        }

        return self::SUCCESS;
    }
}
