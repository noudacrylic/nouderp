<?php

namespace App\Console\Commands;

use App\Core\Journal\Journal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bereskan batch finalisasi produksi yang pembatalannya terhitung DUA KALI.
 *
 * Dulu membatalkan batch memasang jurnal pembalik (production_order_release_void,
 * Dr.WIP / Cr.Persediaan) DAN mem-void jurnal pelepasan aslinya. Saldo hanya menghitung
 * jurnal posted, jadi WIP kelebihan & Persediaan kekurangan sebesar wip_released.
 * Kode sudah dibetulkan (kini hanya jurnal pembalik); perintah ini membetulkan data lama
 * dengan mengembalikan jurnal pelepasan asli ke posted — pasangannya dengan jurnal
 * pembalik jadi saling meniadakan, seperti seharusnya.
 *
 * Hanya menyentuh batch yang: sudah dibatalkan, punya jurnal pembalik aktif, dan jurnal
 * aslinya berstatus void. Idempoten.
 */
class FixDoubleVoidBatchJournals extends Command
{
    protected $signature = 'production:fix-double-void-batches {--dry-run : Hanya tampilkan, tanpa mengubah apa pun}';
    protected $description = 'Kembalikan jurnal pelepasan batch produksi yang ikut di-void saat batch dibatalkan';

    public function handle(): int
    {
        $rows = DB::table('production_finalizations as f')
            ->join('production_orders as po', 'po.id', '=', 'f.production_order_id')
            ->join('journals as j', 'j.id', '=', 'f.journal_id')
            ->join('journals as v', 'v.id', '=', 'f.void_journal_id')
            ->whereNotNull('f.voided_at')
            ->where('j.status', 'void')
            ->where('v.status', '!=', 'void')
            ->leftJoin('accounting_periods as ap', 'ap.id', '=', 'j.period_id')
            ->select('f.id', 'po.order_number', 'f.sequence', 'j.id as journal_id', 'j.date', 'f.wip_released', 'ap.status as period_status')
            ->orderBy('j.date')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada batch yang pembatalannya terhitung dua kali.');
            return self::SUCCESS;
        }

        $this->table(['Batch', 'OP', 'Ke-', 'Jurnal asli', 'Tanggal', 'Nominal'], $rows->map(fn ($r) => [
            $r->id, $r->order_number, $r->sequence, $r->journal_id, $r->date,
            number_format((float) $r->wip_released, 0, ',', '.'),
        ]));
        $total = number_format((float) $rows->sum('wip_released'), 0, ',', '.');

        // Mengubah jurnal di periode yang sudah ditutup mengubah laporan yang sudah final.
        $closed = $rows->filter(fn ($r) => $r->period_status !== null && $r->period_status !== 'open');
        if ($closed->isNotEmpty()) {
            $this->error('Periode akuntansi jurnal berikut sudah ditutup: ' . $closed->pluck('date')->unique()->implode(', ')
                . '. Buka periodenya dulu, atau koreksi dengan jurnal bertanggal hari ini.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn("Dry-run: tidak ada yang diubah. Akan mengembalikan {$rows->count()} jurnal ke posted (WIP turun & Persediaan naik Rp{$total}).");
            return self::SUCCESS;
        }

        Journal::whereIn('id', $rows->pluck('journal_id'))
            ->update(['status' => 'posted', 'voided_at' => null]);

        $this->info("{$rows->count()} jurnal pelepasan dikembalikan ke posted. WIP turun & Persediaan naik Rp{$total}.");

        return self::SUCCESS;
    }
}
