<?php

namespace App\Console\Commands;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bereskan "sisa DP nyangkut" di 1107 (Uang Muka Supplier) yang berasal dari biaya
 * bukan-HPP (mis. Biaya Layanan / admin Shopee) yang tak pernah masuk faktur.
 *
 * Latar: dulu DP dipakai OTOMATIS saat posting faktur. Kalau total yang dibayar (DP)
 * lebih besar dari total faktur (karena biaya admin tak ikut faktur), sisanya nyangkut
 * di 1107 selamanya. Command ini mereklas sisa itu 1107 -> beban admin.
 *
 * SURGICAL: hanya menyentuh DP payment yang SEMUA PO rujukannya (dari catatan
 * "DP untuk PO/...") sudah FULL di-invoice. DP untuk PO yang masih terbuka (faktur
 * belum dibuat) = saldo sah, TIDAK disentuh.
 *
 * Idempoten: setelah direklas remaining_amount=0, jalan ulang otomatis melewatinya.
 * DEFAULT dry-run — wajib --apply untuk benar-benar menyimpan.
 */
class ReclassStuckSupplierDp extends Command
{
    protected $signature = 'purchasing:reclass-stuck-dp
        {--supplier= : Batasi ke satu supplier_id}
        {--account=5103 : Kode akun beban tujuan reklas (default 5103 Beban Administrasi Bank)}
        {--apply : Benar-benar simpan (tanpa flag ini = dry-run)}';

    protected $description = 'Reklas sisa DP nyangkut (biaya admin/layanan) dari 1107 ke beban, hanya untuk PO yang sudah full di-invoice';

    public function handle(PeriodService $periodService): int
    {
        $apply = (bool) $this->option('apply');
        $tag = $apply ? '' : '[DRY-RUN] ';

        $bebanCode = (string) $this->option('account');
        $bebanId = Account::where('code', $bebanCode)->value('id');
        if (!$bebanId) {
            $this->error("Akun beban kode {$bebanCode} tidak ditemukan.");
            return self::FAILURE;
        }

        $payments = SupplierPayment::where('status', 'posted')
            ->where('remaining_amount', '>', 0)
            ->when($this->option('supplier'), fn ($q, $s) => $q->where('supplier_id', $s))
            ->orderBy('supplier_id')->orderBy('payment_date')->orderBy('id')
            ->get();

        $rows = [];
        $stuckTotal = 0.0;
        $legitTotal = 0.0;
        $candidates = [];

        foreach ($payments as $p) {
            $poNumbers = $this->parsePoNumbers($p->notes);
            [$verdict, $reason] = $this->classify($poNumbers);

            if ($verdict === 'stuck') {
                $stuckTotal += (float) $p->remaining_amount;
                $candidates[] = $p;
            } else {
                $legitTotal += (float) $p->remaining_amount;
            }

            $rows[] = [
                $p->supplier_id,
                $p->payment_number,
                number_format((float) $p->remaining_amount, 0, ',', '.'),
                $poNumbers ? implode(', ', $poNumbers) : '(tanpa PO di catatan)',
                $verdict === 'stuck' ? 'NYANGKUT → reklas' : 'sah (' . $reason . ')',
            ];
        }

        $this->info($tag . 'Analisa sisa DP supplier:');
        $this->table(['Supplier', 'Payment', 'Sisa DP', 'PO rujukan', 'Status'], $rows);
        $this->line('Total NYANGKUT (akan direklas): Rp ' . number_format($stuckTotal, 0, ',', '.'));
        $this->line('Total SAH (DP untuk PO terbuka, tidak disentuh): Rp ' . number_format($legitTotal, 0, ',', '.'));

        if (empty($candidates)) {
            $this->comment('Tidak ada sisa DP nyangkut. Selesai.');
            return self::SUCCESS;
        }

        if (!$apply) {
            $this->newLine();
            $this->comment('Ini pratinjau. Jalankan dengan --apply untuk mereklas (BACKUP DB dulu).');
            return self::SUCCESS;
        }

        $now = now();
        $periodService->ensureOpen($now);
        $period = AccountingPeriod::where('year', $now->year)->where('month', $now->month)->first();
        if (!$period) {
            $this->error('Accounting period tidak ditemukan.');
            return self::FAILURE;
        }

        $done = 0;
        foreach ($candidates as $p) {
            DB::transaction(function () use ($p, $bebanId, $period, $now, &$done) {
                $fresh = SupplierPayment::where('id', $p->id)->lockForUpdate()->first();
                $amount = round((float) $fresh->remaining_amount, 2);
                if ($amount <= 0) return; // sudah dibereskan (idempoten)

                $dpAccountId = $fresh->supplier->account_dp_id
                    ?? Account::where('code', '1107')->value('id');

                $journal = Journal::create([
                    'journal_number'   => $this->generateNumber(),
                    'date'             => $now->toDateString(),
                    'period_id'        => $period->id,
                    'reference_type'   => 'dp_reclass',
                    'reference_id'     => $fresh->id,
                    'reference_number' => $fresh->payment_number,
                    'description'      => 'Reklas sisa DP nyangkut (biaya admin) ' . $fresh->payment_number,
                    'status'           => 'posted',
                    'posted_at'        => now(),
                ]);
                $this->journalLine($journal, $bebanId, 'debit', $amount, $fresh, 'Biaya admin/layanan (sisa DP)');
                $this->journalLine($journal, $dpAccountId, 'credit', $amount, $fresh, 'Pelepasan sisa DP nyangkut');

                $fresh->remaining_amount = 0;
                $fresh->notes = trim(($fresh->notes ? $fresh->notes . ' ' : '')
                    . '[Sisa DP Rp ' . number_format($amount, 0, ',', '.') . ' direklas ke beban admin via ' . $journal->journal_number . ']');
                $fresh->save();
                $done++;
            });
        }

        $this->info("Selesai. {$done} payment direklas, total Rp " . number_format($stuckTotal, 0, ',', '.') . '.');
        return self::SUCCESS;
    }

    /** Ambil nomor PO (format PO/YYYY/MM/NNNN) dari catatan payment. */
    private function parsePoNumbers(?string $notes): array
    {
        if (!$notes) return [];
        preg_match_all('#PO/\d{4}/\d{2}/\d{4}#', $notes, $m);
        return array_values(array_unique($m[0] ?? []));
    }

    /**
     * Tentukan apakah sisa DP nyangkut (semua PO rujukan sudah full invoice) atau sah.
     * @return array{0:string,1:string} [verdict('stuck'|'legit'), reason]
     */
    private function classify(array $poNumbers): array
    {
        if (empty($poNumbers)) {
            return ['legit', 'tanpa PO — cek manual'];
        }
        $pos = PurchaseOrder::with('items')->whereIn('po_number', $poNumbers)->get();
        if ($pos->count() < count($poNumbers)) {
            return ['legit', 'PO tak ditemukan — cek manual'];
        }
        foreach ($pos as $po) {
            $totalQty = (float) $po->items->sum('qty');
            $invQty = (float) $po->items->sum('qty_invoiced');
            $hasInvoice = $po->invoices()->where('status', 'posted')->exists();
            if (!$hasInvoice || $totalQty <= 0 || $invQty < $totalQty - 0.0001) {
                return ['legit', 'PO ' . $po->po_number . ' belum full invoice'];
            }
        }
        return ['stuck', 'semua PO full invoice'];
    }

    private function journalLine(Journal $journal, int $accountId, string $type, float $amount, SupplierPayment $p, string $desc): void
    {
        JournalLine::create([
            'journal_id'       => $journal->id,
            'account_id'       => $accountId,
            'debit'            => $type === 'debit' ? $amount : 0,
            'credit'           => $type === 'credit' ? $amount : 0,
            'description'      => $desc,
            'reference_type'   => 'dp_reclass',
            'reference_id'     => $p->id,
            'reference_number' => $p->payment_number,
        ]);
    }

    private function generateNumber(): string
    {
        $prefix = 'DPR/' . now()->format('Y/m') . '/';
        $latest = Journal::where('journal_number', 'LIKE', $prefix . '%')
            ->orderByDesc('journal_number')->value('journal_number');
        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}
