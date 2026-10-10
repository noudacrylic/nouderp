<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\BankReconciliation;
use App\Modules\Finance\Models\BankReconciliationLine;
use App\Modules\Finance\Models\BankStatementLine;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Accounting\Account;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class BankReconciliationService
{
    use NumberGeneratorTrait;

    /** Jurnal Saldo Awal akun (OpeningBalanceService) — masuk saldo awal periode, bukan baris. */
    private const OPENING_BALANCE_TYPE = 'opening_balance';

    public function createDraft(array $data): BankReconciliation
    {
        $start = Carbon::parse($data['start_date']);
        $end   = Carbon::parse($data['end_date']);
        if ($end->lt($start)) throw new DomainException('Tanggal akhir tidak boleh sebelum tanggal mulai.');

        $account = Account::findOrFail($data['account_id']);
        if (!$account->is_cash_account) {
            throw new DomainException('Akun yang direkonsiliasi wajib akun kas/bank.');
        }

        return DB::transaction(function () use ($data, $start, $end) {
            $opening = $this->computeOpeningBalance((int) $data['account_id'], $start, $end);
            $bookEnd = $this->computeBookBalance((int) $data['account_id'], $end);

            $br = BankReconciliation::create([
                'number'            => $this->generateNumber(BankReconciliation::class, 'BR'),
                'account_id'        => $data['account_id'],
                'start_date'        => $start->toDateString(),
                'end_date'          => $end->toDateString(),
                'period_year'       => $end->year,
                'period_month'      => $end->month,
                'opening_balance'   => $opening,
                'book_balance'      => $bookEnd,
                'statement_balance' => $data['statement_balance'] ?? 0,
                'difference'        => round($bookEnd - (float) ($data['statement_balance'] ?? 0), 2),
                'status'            => 'draft',
                'notes'             => $data['notes'] ?? null,
                'created_by'        => auth()->id(),
            ]);

            // Pre-load all journal lines in range as unmatched lines
            $journalLineIds = $this->getJournalLineIds((int) $data['account_id'], $start, $end);
            foreach ($journalLineIds as $jlId) {
                BankReconciliationLine::create([
                    'bank_reconciliation_id' => $br->id,
                    'journal_line_id'        => $jlId,
                    'is_matched'             => false,
                ]);
            }

            return $br;
        });
    }

    public function update(BankReconciliation $br, array $data): BankReconciliation
    {
        if (!$br->isDraft()) throw new DomainException('Hanya draft yang bisa diedit.');

        return DB::transaction(function () use ($br, $data) {
            $statementBalance = (float) ($data['statement_balance'] ?? 0);
            $br->update([
                'statement_balance' => $statementBalance,
                'difference'        => round((float) $br->book_balance - $statementBalance, 2),
                'notes'             => $data['notes'] ?? null,
            ]);

            // Update matches
            $matchedIds = $data['matched_journal_line_ids'] ?? [];
            $matchedIds = array_map('intval', $matchedIds);

            BankReconciliationLine::where('bank_reconciliation_id', $br->id)
                ->update(['is_matched' => false]);

            if (!empty($matchedIds)) {
                BankReconciliationLine::where('bank_reconciliation_id', $br->id)
                    ->whereIn('journal_line_id', $matchedIds)
                    ->update(['is_matched' => true]);
            }

            return $br;
        });
    }

    /**
     * Sinkronkan BR lines dengan journal lines aktual di periode.
     * Tambahkan line baru yang muncul setelah BR dibuat (mis. dari quick-add modal),
     * hapus line yang journal-nya sudah tidak ada (void). Hanya untuk draft.
     * Recompute book_balance & difference setelah sync.
     */
    public function syncLines(BankReconciliation $br): BankReconciliation
    {
        if (!$br->isDraft()) return $br;

        $start = Carbon::parse($br->start_date);
        $end   = Carbon::parse($br->end_date);

        return DB::transaction(function () use ($br, $start, $end) {
            $expected = $this->getJournalLineIds($br->account_id, $start, $end);
            $existing = BankReconciliationLine::where('bank_reconciliation_id', $br->id)
                ->pluck('journal_line_id')->all();

            $toAdd    = array_diff($expected, $existing);
            $toRemove = array_diff($existing, $expected);

            foreach ($toAdd as $jlId) {
                BankReconciliationLine::create([
                    'bank_reconciliation_id' => $br->id,
                    'journal_line_id'        => $jlId,
                    'is_matched'             => false,
                ]);
            }
            if (!empty($toRemove)) {
                BankReconciliationLine::where('bank_reconciliation_id', $br->id)
                    ->whereIn('journal_line_id', $toRemove)
                    ->delete();
            }

            $book = $this->computeBookBalance($br->account_id, $end);
            $br->update([
                // Saldo awal ikut dihitung ulang: Saldo Awal akun bisa diinput/diedit
                // setelah draft dibuat.
                'opening_balance' => $this->computeOpeningBalance($br->account_id, $start, $end),
                'book_balance' => $book,
                'difference'   => round($book - (float) $br->statement_balance, 2),
            ]);

            return $br;
        });
    }

    public function complete(BankReconciliation $br): BankReconciliation
    {
        if (!$br->isDraft()) throw new DomainException('Hanya draft yang bisa diselesaikan.');

        $br->status = 'completed';
        $br->completed_at = now();
        $br->save();
        return $br;
    }

    public function void(BankReconciliation $br): BankReconciliation
    {
        if (!$br->canBeVoided()) throw new DomainException('Hanya rekonsiliasi completed yang bisa di-void.');
        $br->status = 'void';
        $br->voided_at = now();
        $br->save();
        return $br;
    }

    /**
     * Saldo akhir per buku per akun pada/sebelum tanggal $end (jurnal posted).
     */
    public function computeBookBalance(int $accountId, Carbon $end): float
    {
        $account = Account::findOrFail($accountId);
        $totals = JournalLine::where('account_id', $accountId)
            ->whereHas('journal', function ($q) use ($end) {
                $q->where('status', 'posted')->whereDate('date', '<=', $end);
            })
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        $debit = (float) ($totals->d ?? 0);
        $credit = (float) ($totals->c ?? 0);
        // Cash account = asset = normal debit balance
        return round($debit - $credit, 2);
    }

    /**
     * Saldo s/d sehari sebelum periode + jurnal Saldo Awal (opening_balance) yang jatuh
     * DI DALAM periode. Saldo Awal akun bukan mutasi bank — tak ada padanannya di koran —
     * jadi ditampilkan sebagai "Saldo awal periode", bukan baris transaksi
     * (lihat getJournalLineIds). Saldo akhir buku tetap sama.
     */
    public function computeOpeningBalance(int $accountId, Carbon $start, ?Carbon $end = null): float
    {
        $opening = $this->computeBookBalance($accountId, $start->copy()->subDay());
        if (!$end) return $opening;

        $ob = JournalLine::where('account_id', $accountId)
            ->whereHas('journal', function ($q) use ($start, $end) {
                $q->where('status', 'posted')
                  ->where('reference_type', self::OPENING_BALANCE_TYPE)
                  ->whereDate('date', '>=', $start)
                  ->whereDate('date', '<=', $end);
            })
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')
            ->value('net');

        return round($opening + (float) $ob, 2);
    }

    /**
     * @return int[] List journal_line.id
     */
    public function getJournalLineIds(int $accountId, Carbon $start, Carbon $end): array
    {
        return JournalLine::where('account_id', $accountId)
            ->whereHas('journal', function ($q) use ($start, $end) {
                $q->where('status', 'posted')
                  ->where('reference_type', '!=', self::OPENING_BALANCE_TYPE)
                  ->whereDate('date', '>=', $start)
                  ->whereDate('date', '<=', $end);
            })
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    // =====================================================================
    //  REKENING KORAN (Bank Statement) — upload Excel & pencocokan otomatis
    // =====================================================================

    /**
     * Import baris rekening koran dari raw rows Excel (header sudah di-strip).
     * Kolom: 0 Tanggal, 1 Keterangan, 2 Uang Masuk, 3 Uang Keluar.
     * Replace total data koran lama untuk BR ini. Return ringkasan.
     */
    public function importStatement(BankReconciliation $br, array $rawRows, array $header = []): array
    {
        if (!$br->isDraft()) throw new DomainException('Hanya draft yang bisa di-upload rekening koran.');

        // Laporan asli Midtrans (Balance Transaction Report) → ubah ke 4 kolom standar.
        if ($midtransCols = $this->midtransColumns($header)) {
            $rawRows = $this->convertMidtransRows($rawRows, $midtransCols);
        }

        $parsed  = [];
        $skipped = [];
        foreach ($rawRows as $idx => $row) {
            $rowNum  = $idx + 2; // +1 header sudah di-strip, +1 supaya 1-indexed
            $rawDate = $row[0] ?? null;
            $desc    = trim((string) ($row[1] ?? ''));
            $masuk   = $this->statementNumber($row[2] ?? 0);
            $keluar  = $this->statementNumber($row[3] ?? 0);

            // Lewati baris kosong total (biasanya baris petunjuk / spasi)
            if (($rawDate === null || $rawDate === '') && $masuk == 0 && $keluar == 0) continue;

            $date = $this->parseStatementDate($rawDate);
            if (!$date) {
                $skipped[] = ['row' => $rowNum, 'reason' => 'tanggal tidak valid (' . $rawDate . ')'];
                continue;
            }
            $amount = round($masuk - $keluar, 2);
            if ($amount == 0.0) {
                $skipped[] = ['row' => $rowNum, 'reason' => 'nilai 0 — Uang Masuk & Keluar kosong'];
                continue;
            }

            $parsed[] = [
                'statement_date' => $date->toDateString(),
                'amount'         => $amount,
                'description'    => $desc !== '' ? mb_substr($desc, 0, 255) : null,
                'source_row'     => $rowNum,
            ];
        }

        if (empty($parsed)) {
            throw new DomainException('Tidak ada baris valid. Pastikan urutan kolom: Tanggal | Keterangan | Uang Masuk | Uang Keluar.');
        }

        DB::transaction(function () use ($br, $parsed) {
            BankStatementLine::where('bank_reconciliation_id', $br->id)->delete();
            foreach ($parsed as $p) {
                BankStatementLine::create($p + ['bank_reconciliation_id' => $br->id]);
            }
        });

        $start = Carbon::parse($br->start_date)->startOfDay();
        $end   = Carbon::parse($br->end_date)->endOfDay();
        $outOfPeriod = 0;
        foreach ($parsed as $p) {
            $d = Carbon::parse($p['statement_date']);
            if ($d->lt($start) || $d->gt($end)) $outOfPeriod++;
        }

        return [
            'imported'      => count($parsed),
            'skipped'       => $skipped,
            'out_of_period' => $outOfPeriod,
        ];
    }

    /**
     * Sel yang sudah berupa ANGKA dipakai apa adanya — clean_number() membaca "11.144"
     * sebagai sebelas ribu, padahal di Excel itu 11,144 (fee Midtrans berdesimal).
     * Hanya teks yang lewat clean_number() (format Indonesia "1.234.567,89").
     */
    private function statementNumber($v): float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        return (float) clean_number($v);
    }

    /**
     * Kenali header laporan Midtrans: Date Created | Order ID | Transaction Type | Channel |
     * Status | Reference Id | Amount | Total Fee | Notes. Return indeks kolom, atau null.
     */
    private function midtransColumns(array $header): ?array
    {
        $norm = array_map(fn($h) => strtolower(trim((string) $h)), $header);
        $want = ['date' => 'date created', 'order' => 'order id', 'type' => 'transaction type',
                 'channel' => 'channel', 'status' => 'status', 'amount' => 'amount', 'fee' => 'total fee'];
        $cols = [];
        foreach ($want as $key => $label) {
            $i = array_search($label, $norm, true);
            if ($i === false) return null;
            $cols[$key] = $i;
        }
        return $cols;
    }

    /**
     * Satu baris Midtrans = satu mutasi saldo BERSIH (Amount − fee bulat; Total Fee bernilai negatif) — sama dengan cara ERP mencatat "Penerimaan Kas (Net)" ke Saldo
     * Midtrans, jadi bisa cocok persis. Withdrawal (pencairan ke bank) jadi uang keluar.
     */
    private function convertMidtransRows(array $rows, array $c): array
    {
        $skipStatus = ['pending', 'failure', 'failed', 'cancel', 'deny', 'expire'];
        $out = [];
        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row[$c['status']] ?? '')));
            if (in_array($status, $skipStatus, true)) continue;

            $amount = $this->statementNumber($row[$c['amount']] ?? 0);
            $fee    = $this->statementNumber($row[$c['fee']] ?? 0);
            // Fee dibulatkan DULU, baru dikurangkan — sama dgn ERP (MidtransFeeCalculator menyimpan
            // fee bulat). 409.500 − 2.866,5 → ERP 406.633; round(bersih) malah 406.634.
            $net    = round($amount) - round(abs($fee));
            $type    = trim((string) ($row[$c['type']] ?? ''));
            $channel = trim((string) ($row[$c['channel']] ?? ''));
            $order   = trim((string) ($row[$c['order']] ?? ''));

            $desc = trim("{$type} {$channel} {$order}");
            if ($fee != 0) {
                $desc .= ' (bruto ' . number_format($amount, 0, ',', '.')
                       . ' − fee ' . number_format(abs($fee), 0, ',', '.') . ')';
            }
            $out[] = [$row[$c['date']] ?? null, $desc, $net > 0 ? $net : 0, $net < 0 ? -$net : 0];
        }
        return $out;
    }

    public function clearStatement(BankReconciliation $br): void
    {
        BankStatementLine::where('bank_reconciliation_id', $br->id)->delete();
    }

    private function parseStatementDate($raw): ?Carbon
    {
        if ($raw === null || $raw === '') return null;

        // Excel menyimpan tanggal sebagai serial number → konversi.
        if (is_numeric($raw)) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw);
                return Carbon::instance($dt)->startOfDay();
            } catch (\Throwable $e) { /* fall through ke parse string */ }
        }

        $s = trim((string) $raw);
        // dd/mm/yyyy atau dd-mm-yyyy (format Indonesia) → eksplisit supaya tak terbalik.
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $s, $m)) {
            try {
                return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
            } catch (\Throwable $e) { return null; }
        }
        try {
            return Carbon::parse($s)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Cocokkan baris rekening koran dengan transaksi ERP (journal lines) di BR ini.
     * Greedy 2 tahap: (1) tanggal & nilai sama, (2) nilai sama tanggal beda.
     * Return map per journal_line_id + daftar baris koran yang belum ada padanan.
     */
    public function buildStatementMatch(BankReconciliation $br): array
    {
        $stmtArr = $br->statementLines->values()->all();

        // Nilai bertanda per journal line (debit - credit) + tanggal jurnal.
        $erp = [];
        foreach ($br->lines as $l) {
            $jl = $l->journalLine;
            if (!$jl) continue;
            $erp[$jl->id] = [
                'amount' => round((float) $jl->debit - (float) $jl->credit, 2),
                'date'   => Carbon::parse($jl->journal->date)->toDateString(),
            ];
        }

        $key = fn($amt) => (string) (int) round((float) $amt); // bandingkan dlm rupiah bulat

        $lineMatch = [];
        $usedStmt  = [];

        // Tahap 1: tanggal & nilai sama (cocok persis)
        foreach ($erp as $jlId => $e) {
            foreach ($stmtArr as $i => $s) {
                if (isset($usedStmt[$i])) continue;
                if ($key($s->amount) === $key($e['amount'])
                    && $s->statement_date->toDateString() === $e['date']) {
                    $lineMatch[$jlId] = [
                        'status' => 'exact',
                        'date'   => $s->statement_date->toDateString(),
                        'amount' => (float) $s->amount,
                        'desc'   => $s->description,
                    ];
                    $usedStmt[$i] = true;
                    break;
                }
            }
        }
        // Tahap 2: nilai sama, tanggal beda (kemungkinan salah input tanggal di ERP)
        foreach ($erp as $jlId => $e) {
            if (isset($lineMatch[$jlId])) continue;
            foreach ($stmtArr as $i => $s) {
                if (isset($usedStmt[$i])) continue;
                if ($key($s->amount) === $key($e['amount'])) {
                    $lineMatch[$jlId] = [
                        'status' => 'amount',
                        'date'   => $s->statement_date->toDateString(),
                        'amount' => (float) $s->amount,
                        'desc'   => $s->description,
                    ];
                    $usedStmt[$i] = true;
                    break;
                }
            }
        }

        // Tahap 3: satu transaksi ERP = BEBERAPA baris koran di tanggal yang sama.
        // Bank memecah biaya admin jadi baris sendiri (token PLN 500.000 + 3.000,
        // BI-Fast 10.000.000 + 2.500, BPJS 70.000 + 2.750), sedangkan di ERP satu
        // pengeluaran dicatat sekali sebesar totalnya.
        foreach ($erp as $jlId => $e) {
            if (isset($lineMatch[$jlId])) continue;
            $combo = $this->findStatementCombo($stmtArr, $usedStmt, $e);
            if ($combo === null) continue;

            $parts = [];
            foreach ($combo as $i) {
                $usedStmt[$i] = true;
                $parts[] = [
                    'date'   => $stmtArr[$i]->statement_date->toDateString(),
                    'amount' => (float) $stmtArr[$i]->amount,
                    'desc'   => $stmtArr[$i]->description,
                ];
            }
            $lineMatch[$jlId] = [
                'status' => 'group',
                'date'   => $parts[0]['date'],
                'amount' => array_sum(array_column($parts, 'amount')),
                'desc'   => $parts[0]['desc'],
                'parts'  => $parts,
            ];
        }

        // Baris koran tanpa padanan di ERP → harus diinput.
        $unmatched = [];
        foreach ($stmtArr as $i => $s) {
            if (isset($usedStmt[$i])) continue;
            $unmatched[] = [
                'id'     => $s->id,
                'date'   => $s->statement_date->toDateString(),
                'amount' => (float) $s->amount,
                'desc'   => $s->description,
            ];
        }

        return [
            'lineMatch'       => $lineMatch,
            'unmatched'       => $unmatched,
            'total'           => count($stmtArr),
            'exact'           => count(array_filter($lineMatch, fn($m) => $m['status'] === 'exact')),
            'amount_only'     => count(array_filter($lineMatch, fn($m) => $m['status'] === 'amount')),
            'grouped'         => count(array_filter($lineMatch, fn($m) => $m['status'] === 'group')),
            'unmatched_count' => count($unmatched),
        ];
    }

    /**
     * Cari gabungan 2–3 baris koran (tanggal & arah sama, belum terpakai) yang
     * jumlahnya persis sama dengan satu transaksi ERP. Dipakai untuk pola bank
     * yang memisah biaya admin ke baris tersendiri.
     *
     * @return int[]|null index pada $stmtArr, atau null bila tak ada gabungan pas.
     */
    private function findStatementCombo(array $stmtArr, array $usedStmt, array $erpLine): ?array
    {
        $target = (int) round((float) $erpLine['amount']);
        if ($target === 0) return null;

        // Kandidat: tanggal sama, arah sama (jangan adu masuk lawan keluar),
        // dan nilainya tidak melebihi target.
        $cand = [];
        foreach ($stmtArr as $i => $s) {
            if (isset($usedStmt[$i])) continue;
            if ($s->statement_date->toDateString() !== $erpLine['date']) continue;
            $amt = (int) round((float) $s->amount);
            if ($amt === 0 || ($amt > 0) !== ($target > 0)) continue;
            if (abs($amt) > abs($target)) continue;
            $cand[$i] = $amt;
        }
        if (count($cand) < 2) return null;

        // Batasi ruang cari: pola nyata selalu "pokok + 1-2 biaya", dan kandidat
        // per tanggal jumlahnya kecil. Nilai terbesar dulu supaya pokoknya kepilih
        // (pakai nilai mutlak — untuk uang keluar angkanya negatif).
        uasort($cand, fn($a, $b) => abs($b) <=> abs($a));
        $idx = array_keys($cand);
        $n   = count($idx);

        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                if ($cand[$idx[$a]] + $cand[$idx[$b]] === $target) {
                    return [$idx[$a], $idx[$b]];
                }
                for ($c = $b + 1; $c < $n; $c++) {
                    if ($cand[$idx[$a]] + $cand[$idx[$b]] + $cand[$idx[$c]] === $target) {
                        return [$idx[$a], $idx[$b], $idx[$c]];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Apakah akun bank tertentu sudah punya rekonsiliasi 'completed' untuk bulan tersebut?
     */
    public function isMonthReconciled(int $accountId, int $year, int $month): bool
    {
        return BankReconciliation::where('account_id', $accountId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', 'completed')
            ->exists();
    }

    /**
     * Daftar akun bank/kas aktif yang BELUM direkonsiliasi untuk bulan tersebut.
     * @return Account[]
     */
    public function getUnreconciledAccounts(int $year, int $month): array
    {
        $cashAccounts = Account::where('is_cash_account', 1)
            ->where('is_active', 1)
            ->orderBy('code')
            ->get();
        $missing = [];
        foreach ($cashAccounts as $acc) {
            if (!$this->isMonthReconciled($acc->id, $year, $month)) {
                $missing[] = $acc;
            }
        }
        return $missing;
    }
}
