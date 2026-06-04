<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\MarketplaceSettlement;
use App\Modules\Finance\Models\MarketplaceSettlementLine;
use App\Models\MarketplaceConfig;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Carbon\Carbon;
use DomainException;

class MarketplaceSettlementService
{
    use NumberGeneratorTrait;

    public function __construct(
        protected PeriodService $periodService
    ) {}

    /**
     * Parse Excel format BAKU 4 kolom: marketplace | order_ref | settlement_date | net_amount.
     * Header row WAJIB di baris 1. Marketplace di-lookup ke config via customers.marketplace_code
     * (case-insensitive), atau fallback ke nama customer.
     *
     * Return: [
     *   'rows_per_config' => [config_id => [rows...]],
     *   'unmapped'        => [string codes yang tidak ketemu config-nya],
     *   'total_rows'      => int,
     * ]
     */
    public function parseStandardFormat(string $absolutePath): array
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false); // 0-based

        if (count($rows) < 2) {
            throw new DomainException('File kosong atau tidak ada baris data (header + minimal 1 row).');
        }

        // Header row 0 — normalize ke lowercase tanpa spasi untuk fleksibilitas
        $header = array_map(fn($v) => strtolower(trim((string) $v)), $rows[0]);
        $idxMarketplace = $this->findHeaderIdx($header, ['marketplace', 'mp', 'channel']);
        $idxOrderRef    = $this->findHeaderIdx($header, ['order_ref', 'order ref', 'nomor pesanan', 'order_number', 'no_pesanan', 'po_number']);
        $idxDate        = $this->findHeaderIdx($header, ['settlement_date', 'tanggal', 'date', 'tanggal_settlement']);
        $idxNet         = $this->findHeaderIdx($header, ['net_amount', 'net', 'jumlah', 'amount', 'jumlah_diterima']);

        if ($idxMarketplace === null || $idxOrderRef === null || $idxNet === null) {
            throw new DomainException(
                'Header format baku tidak lengkap. Wajib: marketplace, order_ref, net_amount (settlement_date opsional). ' .
                'Header yang ditemukan: ' . implode(', ', array_filter($header))
            );
        }

        // Pre-load semua marketplace_configs aktif + customer info untuk lookup cepat
        $configs = MarketplaceConfig::with('customer')->where('is_active', 1)->get();
        $codeToConfig = []; // 'shopee' => MarketplaceConfig
        foreach ($configs as $cfg) {
            $code = $this->resolveMarketplaceCode($cfg);
            if ($code) $codeToConfig[$code] = $cfg;
        }

        $rowsPerConfig = [];
        $unmapped = [];

        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $mpRaw = strtolower(trim((string) ($r[$idxMarketplace] ?? '')));
            $orderRef = trim((string) ($r[$idxOrderRef] ?? ''));
            if ($mpRaw === '' || $orderRef === '') continue;

            $cfg = $codeToConfig[$mpRaw] ?? null;
            if (!$cfg) {
                $unmapped[$mpRaw] = ($unmapped[$mpRaw] ?? 0) + 1;
                continue;
            }

            $rowsPerConfig[$cfg->id][] = [
                'order_ref'       => $orderRef,
                'settlement_date' => $idxDate !== null ? $this->toDate($r[$idxDate] ?? null) : null,
                'net_amount'      => $this->toNumber($r[$idxNet] ?? 0),
                'raw_row'         => json_encode($r, JSON_UNESCAPED_UNICODE),
            ];
        }

        return [
            'rows_per_config' => $rowsPerConfig,
            'unmapped'        => array_keys($unmapped),
            'total_rows'      => array_sum(array_map('count', $rowsPerConfig)),
        ];
    }

    /**
     * Bikin draft settlement per marketplace dari hasil parseStandardFormat.
     * Return collection dari MarketplaceSettlement yang dibuat.
     */
    public function createDraftsFromStandard(array $rowsPerConfig, array $meta): array
    {
        $created = [];
        foreach ($rowsPerConfig as $configId => $rows) {
            $config = MarketplaceConfig::find($configId);
            if (!$config || empty($rows)) continue;

            // Convert format baku → format internal yang createDraft expect:
            // Tidak ada gross/fee_actual dari Excel — gross diambil dari invoice.grand_total
            // saat matching, fee_actual = grand_total - net_amount.
            $internalRows = array_map(function ($r) {
                return [
                    'order_ref'       => $r['order_ref'],
                    'settlement_date' => $r['settlement_date'],
                    // Gross/fee aktual ditentukan saat match invoice (di createDraft).
                    // Kalau invoice tidak ketemu, gross=net (assumption: transaksi tanpa biaya).
                    'gross_amount'    => 0,
                    'fee_actual'      => 0,
                    'net_amount'      => $r['net_amount'],
                    'raw_row'         => $r['raw_row'] ?? null,
                ];
            }, $rows);

            $created[] = $this->createDraft($config, $internalRows, $meta);
        }
        return $created;
    }

    /**
     * Resolve kode marketplace dari config:
     * 1. customers.marketplace_code (kalau di-set)
     * 2. fallback ke lower(customer.name) tanpa spasi
     */
    protected function resolveMarketplaceCode(MarketplaceConfig $config): ?string
    {
        $cust = $config->customer;
        if (!$cust) return null;
        if (!empty($cust->marketplace_code)) return strtolower(trim($cust->marketplace_code));
        return strtolower(trim(preg_replace('/\s+/', '', $cust->name ?? '')));
    }

    protected function findHeaderIdx(array $header, array $candidates): ?int
    {
        foreach ($candidates as $cand) {
            $idx = array_search(strtolower($cand), $header, true);
            if ($idx !== false) return $idx;
        }
        return null;
    }

    public function createDraft(MarketplaceConfig $config, array $rows, array $meta): MarketplaceSettlement
    {
        return DB::transaction(function () use ($config, $rows, $meta) {
            $totals = [
                'gross' => 0, 'fee_actual' => 0, 'fee_config' => 0, 'fee_diff' => 0, 'net' => 0,
            ];

            $ms = MarketplaceSettlement::create([
                'number'                => $this->generateNumber(MarketplaceSettlement::class, 'MS'),
                'date'                  => $meta['date'] ?? now()->toDateString(),
                'marketplace_config_id' => $config->id,
                'source_filename'       => $meta['source_filename'] ?? null,
                'status'                => 'draft',
                'notes'                 => $meta['notes'] ?? null,
                'created_by'            => auth()->id(),
            ]);

            foreach ($rows as $row) {
                // Match invoice via sales_orders.customer_po_number → sales_order_id → sales_invoice
                // Filter by customer_id agar tidak salah ambil PO number yang kebetulan sama dari customer lain.
                $invoice = $this->matchInvoiceByOrderRef($row['order_ref'], $config->customer_id);

                // Gross diambil dari invoice (kalau ketemu). Kalau tidak ketemu, fallback ke
                // gross dari row Excel (kalau ada) atau net (asumsi tanpa biaya).
                $gross = (float) ($row['gross_amount'] ?? 0);
                if ($invoice && $gross <= 0) {
                    $gross = (float) $invoice->grand_total;
                }
                if ($gross <= 0) {
                    $gross = (float) $row['net_amount'];  // last fallback
                }

                // Fee aktual = gross - net (apa pun jenis biaya, di-treat gabungan)
                $feeActual = round($gross - (float) $row['net_amount'], 2);
                if ($feeActual < 0) $feeActual = 0;  // safety: kalau net > gross (mis. cashback), treat fee=0

                $feeConfig = $this->computeConfigFee($config, $gross);
                $feeDiff = round($feeActual - $feeConfig, 2);

                MarketplaceSettlementLine::create([
                    'marketplace_settlement_id' => $ms->id,
                    'order_ref'                 => $row['order_ref'],
                    'settlement_date'           => $row['settlement_date'] ?? null,
                    'gross_amount'              => $gross,
                    'fee_actual'                => $feeActual,
                    'fee_config'                => $feeConfig,
                    'fee_diff'                  => $feeDiff,
                    'net_amount'                => $row['net_amount'],
                    'sales_invoice_id'          => $invoice?->id,
                    'is_matched'                => (bool) $invoice,
                    'raw_row'                   => $row['raw_row'] ?? null,
                    'note'                      => $invoice ? null : 'Invoice tidak ketemu (cek PO number di Sales Order)',
                ]);

                $totals['gross']      += $gross;
                $totals['fee_actual'] += $feeActual;
                $totals['fee_config'] += $feeConfig;
                $totals['fee_diff']   += $feeDiff;
                $totals['net']        += (float) $row['net_amount'];
            }

            $ms->update([
                'total_gross'      => round($totals['gross'], 2),
                'total_fee_actual' => round($totals['fee_actual'], 2),
                'total_fee_config' => round($totals['fee_config'], 2),
                'total_fee_diff'   => round($totals['fee_diff'], 2),
                'total_net'        => round($totals['net'], 2),
            ]);

            return $ms;
        });
    }

    public function post(MarketplaceSettlement $ms): MarketplaceSettlement
    {
        if ($ms->isPosted()) throw new DomainException('Sudah diposting.');
        if ($ms->isVoid())   throw new DomainException('Sudah di-void.');

        $payDate = Carbon::parse($ms->date);
        $this->periodService->ensureOpen($payDate);

        $exists = Journal::where('reference_type', 'marketplace_settlement')
            ->where('reference_id', $ms->id)
            ->where('status', '!=', 'void')
            ->exists();
        if ($exists) throw new DomainException('Journal sudah ada untuk settlement ini.');

        return DB::transaction(function () use ($ms, $payDate) {
            $ms->load(['marketplaceConfig', 'lines']);
            $config = $ms->marketplaceConfig;

            if (!$config->account_wallet_id || !$config->account_fee_id || !$config->account_receivable_hold_id) {
                throw new DomainException('Mapping akun marketplace (wallet/fee/hold) belum lengkap di MarketplaceConfig.');
            }
            if ((float) $ms->total_fee_diff != 0 && !$config->account_fee_diff_id) {
                throw new DomainException('Akun selisih biaya admin (fee_diff) belum diset di MarketplaceConfig, padahal ada selisih.');
            }

            $period = AccountingPeriod::where('year', $payDate->year)
                ->where('month', $payDate->month)->first();
            if (!$period) throw new DomainException('Periode akuntansi tidak ditemukan.');

            $journal = Journal::create([
                'journal_number'   => 'MS-J-' . $ms->number,
                'date'             => $ms->date,
                'period_id'        => $period->id,
                'reference_type'   => 'marketplace_settlement',
                'reference_id'     => $ms->id,
                'reference_number' => $ms->number,
                'description'      => 'Marketplace settlement ' . $ms->number,
                'status'           => 'posted',
                'posted_at'        => now(),
            ]);

            $totalGross = (float) $ms->total_gross;
            $totalFeeActual = (float) $ms->total_fee_actual;
            $totalFeeConfig = (float) $ms->total_fee_config;
            $totalFeeDiff   = (float) $ms->total_fee_diff;
            $totalNet       = round($totalGross - $totalFeeActual, 2);

            // Dr Wallet Marketplace (net diterima)
            $this->mkLine($journal, $ms, $config->account_wallet_id, $totalNet, 0, 'Net masuk wallet marketplace');
            // Dr Fee aktual ke akun fee
            if ($totalFeeActual > 0) {
                $this->mkLine($journal, $ms, $config->account_fee_id, $totalFeeActual, 0, 'Biaya admin marketplace aktual');
            }
            // Cr Receivable Hold (sebesar gross — karena saat invoice dipost, hold ditandai sebesar gross)
            $this->mkLine($journal, $ms, $config->account_receivable_hold_id, 0, $totalGross, 'Pelepasan saldo ditahan marketplace');

            // Selisih fee aktual vs fee config dipisah ke akun selisih (untuk transparansi)
            if (abs($totalFeeDiff) > 0.001 && $config->account_fee_diff_id) {
                if ($totalFeeDiff > 0) {
                    // Fee aktual > config (rugi tambahan): adjust antara fee_id (excess) dan fee_diff_id
                    // Pendekatan sederhana: keseluruhan biaya admin sudah masuk fee_id, dan fee_diff_id menjadi
                    // koreksi (Cr fee_id sebesar diff, Dr fee_diff_id sebesar diff) supaya fee_id menampilkan
                    // angka sesuai kontrak/config dan selisih jadi terlihat di fee_diff_id.
                    $this->mkLine($journal, $ms, $config->account_fee_id, 0, $totalFeeDiff, 'Reklasifikasi selisih fee ke akun selisih');
                    $this->mkLine($journal, $ms, $config->account_fee_diff_id, $totalFeeDiff, 0, 'Selisih biaya admin marketplace (rugi)');
                } else {
                    // Fee aktual < config (untung): tambah fee_id supaya jadi sesuai config, lalu Cr fee_diff_id
                    $abs = abs($totalFeeDiff);
                    $this->mkLine($journal, $ms, $config->account_fee_id, $abs, 0, 'Reklasifikasi selisih fee ke akun selisih');
                    $this->mkLine($journal, $ms, $config->account_fee_diff_id, 0, $abs, 'Selisih biaya admin marketplace (laba)');
                }
            }

            $this->validateBalance($journal->id);

            $ms->journal_id = $journal->id;
            $ms->status = 'posted';
            $ms->posted_at = now();
            $ms->save();

            return $ms;
        });
    }

    /**
     * Submit hanya baris matched: post settlement aktif dengan matched lines,
     * sisanya (unmatched) dipindah ke settlement baru status draft (pending),
     * supaya bisa di-retry/match setelah data invoice dilengkapi.
     *
     * Return ['posted' => MarketplaceSettlement, 'pending' => ?MarketplaceSettlement].
     */
    public function splitMatchedAndPost(MarketplaceSettlement $ms): array
    {
        if (!$ms->isDraft()) {
            throw new DomainException('Hanya settlement draft yang bisa di-submit.');
        }

        $ms->load('lines');
        $matchedCount   = $ms->lines->where('is_matched', true)->count();
        $unmatchedCount = $ms->lines->where('is_matched', false)->count();

        if ($matchedCount === 0) {
            throw new DomainException('Tidak ada baris matched. Tambahkan invoice marketplace dulu, sistem akan auto-match (atau klik Retry Match).');
        }

        return DB::transaction(function () use ($ms, $unmatchedCount) {
            $pending = null;

            if ($unmatchedCount > 0) {
                // 1. Bikin settlement pending baru
                $pending = MarketplaceSettlement::create([
                    'number'                => $this->generateNumber(MarketplaceSettlement::class, 'MS'),
                    'date'                  => $ms->date,
                    'marketplace_config_id' => $ms->marketplace_config_id,
                    'source_filename'       => $ms->source_filename,
                    'status'                => 'draft',
                    'notes'                 => 'Pending — split dari ' . $ms->number . '. Auto-match saat invoice marketplace baru dibuat.',
                    'created_by'            => auth()->id(),
                ]);

                // 2. Pindahkan unmatched lines ke settlement baru
                MarketplaceSettlementLine::where('marketplace_settlement_id', $ms->id)
                    ->where('is_matched', false)
                    ->update(['marketplace_settlement_id' => $pending->id]);

                // 3. Recalc totals di dua-duanya
                $this->recalcTotals($ms);
                $this->recalcTotals($pending);
            }

            // 4. Post settlement asal (sekarang hanya matched)
            $ms->refresh()->load('lines');
            $this->post($ms);

            return ['posted' => $ms->fresh(), 'pending' => $pending?->fresh()];
        });
    }

    /**
     * Re-run matching untuk semua line unmatched di settlement draft.
     * Return jumlah line yang baru ketemu match-nya.
     */
    public function retryMatch(MarketplaceSettlement $ms): int
    {
        if (!$ms->isDraft()) {
            throw new DomainException('Hanya settlement draft yang bisa di-retry match.');
        }

        return DB::transaction(function () use ($ms) {
            $config = $ms->marketplaceConfig;
            $newly = 0;

            foreach ($ms->lines()->where('is_matched', false)->get() as $line) {
                $invoice = $this->matchInvoiceByOrderRef($line->order_ref, $config->customer_id);
                if (!$invoice) continue;

                $this->applyMatchToLine($line, $invoice, $config);
                $newly++;
            }

            if ($newly > 0) {
                $this->recalcTotals($ms);
            }
            return $newly;
        });
    }

    /**
     * Auto-trigger: dipanggil setelah invoice baru dibuat utk customer marketplace.
     * Cari semua line draft di customer ini yg order_ref-nya match → langsung set matched.
     * Aman dipanggil tanpa cek customer marketplace (akan kosong-result kalau bukan).
     */
    public function autoRematchForOrderRef(int $customerId, ?string $orderRef): int
    {
        $orderRef = trim((string) $orderRef);
        if ($orderRef === '') return 0;

        $lines = MarketplaceSettlementLine::with('settlement.marketplaceConfig')
            ->where('order_ref', $orderRef)
            ->where('is_matched', false)
            ->whereHas('settlement', function ($q) use ($customerId) {
                $q->where('status', 'draft')
                  ->whereHas('marketplaceConfig', fn($qq) => $qq->where('customer_id', $customerId));
            })
            ->get();

        if ($lines->isEmpty()) return 0;

        $affectedSettlementIds = [];
        $count = 0;

        DB::transaction(function () use ($lines, $customerId, &$count, &$affectedSettlementIds) {
            foreach ($lines as $line) {
                $config = $line->settlement->marketplaceConfig;
                $invoice = $this->matchInvoiceByOrderRef($line->order_ref, $customerId);
                if (!$invoice) continue;

                $this->applyMatchToLine($line, $invoice, $config);
                $affectedSettlementIds[$line->marketplace_settlement_id] = true;
                $count++;
            }

            foreach (array_keys($affectedSettlementIds) as $sid) {
                $ms = MarketplaceSettlement::find($sid);
                if ($ms) $this->recalcTotals($ms);
            }
        });

        return $count;
    }

    /**
     * Helper: apply match data ke 1 line (re-calc gross/fee/diff dari invoice).
     */
    protected function applyMatchToLine(MarketplaceSettlementLine $line, SalesInvoice $invoice, MarketplaceConfig $config): void
    {
        $gross = (float) $invoice->grand_total;
        $feeActual = round($gross - (float) $line->net_amount, 2);
        if ($feeActual < 0) $feeActual = 0;
        $feeConfig = $this->computeConfigFee($config, $gross);
        $feeDiff   = round($feeActual - $feeConfig, 2);

        $line->update([
            'sales_invoice_id' => $invoice->id,
            'is_matched'       => true,
            'gross_amount'     => $gross,
            'fee_actual'       => $feeActual,
            'fee_config'       => $feeConfig,
            'fee_diff'         => $feeDiff,
            'note'             => null,
        ]);
    }

    /**
     * Recalc header totals dari lines (dipanggil setelah pindah/match line).
     */
    protected function recalcTotals(MarketplaceSettlement $ms): void
    {
        $agg = $ms->lines()->selectRaw('
            COALESCE(SUM(gross_amount), 0) AS gross,
            COALESCE(SUM(fee_actual), 0) AS fee_actual,
            COALESCE(SUM(fee_config), 0) AS fee_config,
            COALESCE(SUM(fee_diff), 0) AS fee_diff,
            COALESCE(SUM(net_amount), 0) AS net
        ')->first();

        $ms->update([
            'total_gross'      => round((float) $agg->gross, 2),
            'total_fee_actual' => round((float) $agg->fee_actual, 2),
            'total_fee_config' => round((float) $agg->fee_config, 2),
            'total_fee_diff'   => round((float) $agg->fee_diff, 2),
            'total_net'        => round((float) $agg->net, 2),
        ]);
    }

    public function void(MarketplaceSettlement $ms): MarketplaceSettlement
    {
        if (!$ms->canBeVoided()) {
            throw new DomainException('Hanya settlement posted yang bisa di-void.');
        }
        return DB::transaction(function () use ($ms) {
            if ($ms->journal_id) {
                $journal = Journal::find($ms->journal_id);
                if ($journal) {
                    $journal->status = 'void';
                    $journal->voided_at = now();
                    $journal->save();
                }
            }
            $ms->status = 'void';
            $ms->voided_at = now();
            $ms->save();
            return $ms;
        });
    }

    /**
     * Lookup SalesInvoice via sales_order.customer_po_number = $orderRef.
     * Filter by customer_id supaya tidak salah ambil PO yang kebetulan sama dari customer berbeda.
     * Return invoice posted (atau draft kalau tidak ada posted), atau null kalau tidak ketemu.
     */
    protected function matchInvoiceByOrderRef(string $orderRef, int $customerId): ?SalesInvoice
    {
        // Cari sales_order dulu
        $orderId = DB::table('sales_orders')
            ->where('customer_id', $customerId)
            ->where('customer_po_number', $orderRef)
            ->value('id');
        if (!$orderId) return null;

        return SalesInvoice::where('sales_order_id', $orderId)
            ->orderByRaw("FIELD(status, 'posted', 'paid', 'draft', 'void')")
            ->first();
    }

    protected function computeConfigFee(MarketplaceConfig $config, float $gross): float
    {
        $percent = (float) ($config->admin_fee_percent ?? 0);
        $fixed   = (float) ($config->admin_fee_fixed ?? 0);
        return round(($gross * $percent / 100) + $fixed, 2);
    }

    protected function mkLine(Journal $journal, MarketplaceSettlement $ms, int $accountId, float $debit, float $credit, ?string $desc): void
    {
        if ($debit <= 0 && $credit <= 0) return;
        JournalLine::create([
            'journal_id'      => $journal->id,
            'account_id'      => $accountId,
            'debit'           => $debit,
            'credit'          => $credit,
            'description'     => $desc,
            'reference_type'  => 'marketplace_settlement',
            'reference_id'    => $ms->id,
            'reference_number'=> $ms->number,
        ]);
    }

    protected function validateBalance(int $journalId): void
    {
        $lines = JournalLine::where('journal_id', $journalId)->get();
        $debit = round($lines->sum('debit'), 2);
        $credit = round($lines->sum('credit'), 2);
        if ($debit !== $credit) {
            throw new DomainException("Journal not balanced: Dr=$debit Cr=$credit");
        }
    }

    protected function toNumber($val): float
    {
        if (is_numeric($val)) return (float) $val;
        $s = preg_replace('/[^0-9\-,\.]/', '', (string) $val);
        // Indonesian format: "1.234.567,89" → "1234567.89"
        if (substr_count($s, ',') === 1 && substr_count($s, '.') >= 1) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (substr_count($s, ',') === 1 && !str_contains($s, '.')) {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float) $s : 0.0;
    }

    protected function toDate($val): ?string
    {
        if (empty($val)) return null;
        try {
            // PhpSpreadsheet may return Excel serial date as numeric
            if (is_numeric($val)) {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($val)->format('Y-m-d');
            }
            return Carbon::parse((string) $val)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
