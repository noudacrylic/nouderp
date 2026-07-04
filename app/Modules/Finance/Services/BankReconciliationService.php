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
            $opening = $this->computeOpeningBalance((int) $data['account_id'], $start);
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

    public function computeOpeningBalance(int $accountId, Carbon $start): float
    {
        return $this->computeBookBalance($accountId, $start->copy()->subDay());
    }

    /**
     * @return int[] List journal_line.id
     */
    public function getJournalLineIds(int $accountId, Carbon $start, Carbon $end): array
    {
        return JournalLine::where('account_id', $accountId)
            ->whereHas('journal', function ($q) use ($start, $end) {
                $q->where('status', 'posted')
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
    public function importStatement(BankReconciliation $br, array $rawRows): array
    {
        if (!$br->isDraft()) throw new DomainException('Hanya draft yang bisa di-upload rekening koran.');

        $parsed  = [];
        $skipped = [];
        foreach ($rawRows as $idx => $row) {
            $rowNum  = $idx + 2; // +1 header sudah di-strip, +1 supaya 1-indexed
            $rawDate = $row[0] ?? null;
            $desc    = trim((string) ($row[1] ?? ''));
            $masuk   = (float) clean_number($row[2] ?? 0);
            $keluar  = (float) clean_number($row[3] ?? 0);

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
            'unmatched_count' => count($unmatched),
        ];
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
