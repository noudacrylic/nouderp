<?php

namespace App\Console\Commands;

use App\Core\Journal\Journal;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\ProductionOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bereskan jurnal biaya produksi (production_order_cost) yang nyangkut di OP Batal.
 *
 * Dulu biaya produksi dijurnal (Dr.WIP / Cr.Kas) sejak OP masih Draft, dan pembatalan OP
 * tidak membaliknya — uangnya nyangkut di WIP. Sekarang jurnal biaya dibuat saat konfirmasi
 * dan pembatalan memasang jurnal pembalik. Perintah ini membereskan sisa data lama:
 *
 *  - bawaan  → pasang jurnal pembalik (Dr.Kas / Cr.WIP) bertanggal hari ini; laporan bulan
 *              lalu tidak berubah, jurnal asli tetap ada sebagai jejak audit.
 *  - --hapus → void jurnal aslinya (seolah tak pernah ada; laporan bulan asal ikut berubah).
 *
 * OP Draft yang biayanya terlanjur dijurnal hanya DILAPORKAN, tidak diubah: saat dikonfirmasi
 * jurnalnya dipakai apa adanya (tidak dobel), saat diedit di-void, saat dibatalkan dibalik.
 *
 * Idempoten: OP yang jurnal biayanya sudah dibalik/di-void dilewati.
 */
class FixProductionCostJournals extends Command
{
    protected $signature = 'production:fix-cost-journals
        {--dry-run : Hanya tampilkan, tanpa mengubah apa pun}
        {--hapus : Void jurnal asli alih-alih memasang jurnal pembalik}';
    protected $description = 'Balik (atau void) jurnal biaya produksi yang nyangkut di OP Batal';

    public function handle(ProductionOrderService $service): int
    {
        $rows = DB::table('journals as j')
            ->join('production_orders as po', 'po.id', '=', 'j.reference_id')
            ->where('j.reference_type', 'production_order_cost')
            ->where('j.status', '!=', 'void')
            ->whereIn('po.status', ['draft', 'cancelled'])
            ->whereNotExists(fn ($q) => $q->from('journals as r')
                ->whereColumn('r.reference_id', 'po.id')
                ->where('r.reference_type', 'production_order_cost_cancel')
                ->where('r.status', '!=', 'void'))
            ->select('j.id', 'j.date', 'po.id as order_id', 'po.order_number', 'po.status')
            ->selectSub(fn ($q) => $q->from('journal_lines')->whereColumn('journal_id', 'j.id')->selectRaw('SUM(debit)'), 'total')
            ->orderBy('j.date')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada jurnal biaya produksi yang perlu dibereskan.');
            return self::SUCCESS;
        }

        $this->table(['Jurnal', 'Tanggal', 'OP', 'Status OP', 'Nominal'], $rows->map(fn ($r) => [
            $r->id, $r->date, $r->order_number, $r->status, number_format((float) $r->total, 0, ',', '.'),
        ]));

        $drafts    = $rows->where('status', 'draft');
        $cancelled = $rows->where('status', 'cancelled');

        if ($drafts->isNotEmpty()) {
            $this->line('OP Draft dilewati (' . $drafts->count() . '): jurnalnya dipakai saat konfirmasi, atau dibalik bila OP dibatalkan.');
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: tidak ada yang diubah. Akan ' . ($this->option('hapus') ? 'mem-void' : 'membalik')
                . ' ' . $cancelled->count() . ' jurnal OP Batal.');
            return self::SUCCESS;
        }

        if ($cancelled->isEmpty()) {
            $this->info('Tidak ada OP Batal yang perlu dibereskan.');
            return self::SUCCESS;
        }

        if ($this->option('hapus')) {
            Journal::whereIn('id', $cancelled->pluck('id'))->update(['status' => 'void', 'voided_at' => now()]);
            $this->info($cancelled->count() . ' jurnal biaya produksi OP Batal di-void.');
            return self::SUCCESS;
        }

        $total = 0.0;
        foreach ($cancelled->pluck('order_id')->unique() as $orderId) {
            DB::transaction(function () use ($service, $orderId, &$total) {
                $total += $service->reverseCostJournal(ProductionOrder::findOrFail($orderId));
            });
        }
        $this->info('Jurnal pembalik dipasang untuk ' . $cancelled->pluck('order_id')->unique()->count()
            . ' OP Batal, total Rp' . number_format($total, 0, ',', '.') . '.');

        return self::SUCCESS;
    }
}
