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
    public function parseStandardFormat(string $absolutePath, ?string $extension = null): array
    {
        // File upload punya nama temp tanpa ekstensi, jadi IOFactory tak bisa deteksi format dari
        // nama. Untuk CSV pakai reader eksplisit + tebak encoding (UTF-8 BOM) & delimiter
        // (koma / titik-koma dari Excel Indonesia) otomatis. Selain itu (xlsx/xls) auto-detect.
        if (strtolower((string) $extension) === 'csv') {
            $reader = IOFactory::createReader('Csv');
            $reader->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::GUESS_ENCODING);
            $spreadsheet = $reader->load($absolutePath);
        } else {
            $spreadsheet = IOFactory::load($absolutePath);
        }
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
        // Pass 1: base code (marketplace_code / nama) — paling spesifik, tidak boleh ditimpa.
        foreach ($configs as $cfg) {
            $code = $this->resolveMarketplaceCode($cfg);
            if ($code && !isset($codeToConfig[$code])) $codeToConfig[$code] = $cfg;
        }
        // Pass 2: alias tambahan (mis. label AI "tiktok_tokopedia"/"tiktok" → config Tokopedia).
        foreach ($configs as $cfg) {
            foreach ($this->marketplaceAliases($cfg) as $alias) {
                if ($alias && !isset($codeToConfig[$alias])) $codeToConfig[$alias] = $cfg;
            }
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
                'gross' => 0, 'prebooked' => 0, 'fee_actual' => 0, 'fee_config' => 0, 'fee_diff' => 0, 'net' => 0,
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

            $customerIds = $this->relatedCustomerIds($config);

            foreach ($rows as $row) {
                // Match invoice via sales_orders.customer_po_number → sales_order_id → sales_invoice.
                // Filter by customer (termasuk marketplace kembar, mis. TikTok+Tokopedia) agar tidak
                // salah ambil PO yang kebetulan sama dari customer lain.
                $invoice = $this->matchInvoiceByOrderRef($row['order_ref'], $customerIds);

                [$gross, $prebooked] = $this->resolveGross($invoice, $row);

                // Fee aktual = fee marketplace SEBENARNYA = gross (nilai jual penuh) - net (dana cair).
                $feeActual = round($gross - (float) $row['net_amount'], 2);
                if ($feeActual < 0) $feeActual = 0;  // safety: kalau net > gross (mis. cashback), treat fee=0

                $feeConfig = $this->computeConfigFee($config, $gross);
                // Selisih yang DIBUKUKAN di settlement = fee aktual - fee yang sudah tercatat di faktur.
                $feeDiff = round($feeActual - $prebooked, 2);

                MarketplaceSettlementLine::create([
                    'marketplace_settlement_id' => $ms->id,
                    'order_ref'                 => $row['order_ref'],
                    'settlement_date'           => $row['settlement_date'] ?? null,
                    'gross_amount'              => $gross,
                    'fee_prebooked'             => $prebooked,
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
                $totals['prebooked']  += $prebooked;
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

        // Penyesuaian rekonsiliasi dibebankan di AKHIR BULAN yang direkonsiliasi (bulan dana cair),
        // bukan tanggal upload — supaya biaya transaksi bulan lalu tidak jatuh di bulan ini.
        $payDate = $this->resolvePostingDate($ms);
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

            $period = AccountingPeriod::where('year', $payDate->year)
                ->where('month', $payDate->month)->first();
            if (!$period) throw new DomainException('Periode akuntansi tidak ditemukan.');

            $journal = Journal::create([
                'journal_number'   => 'MS-J-' . $ms->number,
                'date'             => $payDate->toDateString(),
                'period_id'        => $period->id,
                'reference_type'   => 'marketplace_settlement',
                'reference_id'     => $ms->id,
                'reference_number' => $ms->number,
                'description'      => 'Marketplace settlement ' . $ms->number,
                'status'           => 'posted',
                'posted_at'        => now(),
            ]);

            // Saldo ditahan marketplace SUDAH dilepas per-faktur saat faktur dibuat
            // (jurnal `sales_invoice_settlement`: Dr Wallet / Cr Saldo Ditahan sebesar net faktur),
            // dan fee yang tercatat di faktur (fee_prebooked) sudah masuk beban admin di jurnal faktur.
            // Maka pada settlement kita HANYA membukukan SELISIH biaya admin aktual vs yang sudah
            // tercatat di faktur, dengan lawan akun Wallet (menyesuaikan saldo wallet ke dana cair
            // aktual). TIDAK melepas saldo ditahan lagi & tidak re-debit wallet penuh — kalau tidak,
            // saldo ditahan (1202 dst) & wallet akan ter-posting DOBEL.
            $totalFeeActual = (float) $ms->total_fee_actual;
            $totalPrebooked = round((float) $ms->lines->sum('fee_prebooked'), 2);
            $feeToBook      = round($totalFeeActual - $totalPrebooked, 2); // selisih fee yg belum dibukukan

            if ($feeToBook > 0) {
                // Marketplace potong lebih besar dari yang tercatat di faktur.
                $this->mkLine($journal, $ms, $config->account_fee_id, $feeToBook, 0, 'Selisih biaya admin marketplace (tambahan vs faktur)');
                $this->mkLine($journal, $ms, $config->account_wallet_id, 0, $feeToBook, 'Penyesuaian saldo wallet ke dana cair aktual');
            } elseif ($feeToBook < 0) {
                // Marketplace potong lebih sedikit dari yang tercatat di faktur → koreksi (Cr beban, Dr wallet).
                $this->mkLine($journal, $ms, $config->account_wallet_id, abs($feeToBook), 0, 'Penyesuaian saldo wallet ke dana cair aktual');
                $this->mkLine($journal, $ms, $config->account_fee_id, 0, abs($feeToBook), 'Koreksi biaya admin marketplace (lebih kecil dari faktur)');
            }
            // feeToBook == 0 → tidak ada selisih fee; settlement murni pencocokan data, tanpa baris GL.

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
     * Hapus semua line yang TIDAK match (is_matched=false) dari settlement draft.
     * Dipakai saat rekonsiliasi awal: baris tanpa faktur di ERP (fee aktual 0, order pra-ERP)
     * dibuang karena akan dimasukkan lewat Opening Balance. Return jumlah baris terhapus.
     */
    public function deleteUnmatchedLines(MarketplaceSettlement $ms): int
    {
        if (!$ms->isDraft()) {
            throw new DomainException('Hanya settlement draft yang bisa diedit.');
        }

        return DB::transaction(function () use ($ms) {
            $deleted = (int) $ms->lines()->where('is_matched', false)->delete();
            if ($deleted > 0) {
                $this->recalcTotals($ms);
            }
            return $deleted;
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
            $customerIds = $this->relatedCustomerIds($config);
            $newly = 0;

            foreach ($ms->lines()->where('is_matched', false)->get() as $line) {
                $invoice = $this->matchInvoiceByOrderRef($line->order_ref, $customerIds);
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
        // $orderRef di sini = customer_po_number SO (MASIH ada prefix Jubelio), jadi wajib
        // dinormalkan dulu sebelum dibandingkan dengan order_ref settlement (bare number).
        $core = $this->normalizeOrderRef($orderRef);
        if ($core === '') return 0;

        // Grup marketplace kembar (mis. TikTok+Tokopedia) supaya invoice channel A ikut
        // match ke settlement draft channel B yang satu grup.
        $cfg = MarketplaceConfig::where('customer_id', $customerId)->where('is_active', 1)->first();
        $relatedIds = $cfg ? $this->relatedCustomerIds($cfg) : [$customerId];

        $lines = MarketplaceSettlementLine::with('settlement.marketplaceConfig')
            ->where('is_matched', false)
            ->whereHas('settlement', function ($q) use ($relatedIds) {
                $q->where('status', 'draft')
                  ->whereHas('marketplaceConfig', fn($qq) => $qq->whereIn('customer_id', $relatedIds));
            })
            ->get()
            ->filter(fn($l) => $this->normalizeOrderRef($l->order_ref) === $core)
            ->values();

        if ($lines->isEmpty()) return 0;

        $affectedSettlementIds = [];
        $count = 0;

        DB::transaction(function () use ($lines, &$count, &$affectedSettlementIds) {
            foreach ($lines as $line) {
                $config = $line->settlement->marketplaceConfig;
                $invoice = $this->matchInvoiceByOrderRef($line->order_ref, $this->relatedCustomerIds($config));
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
        [$gross, $prebooked] = $this->resolveGross($invoice, ['net_amount' => $line->net_amount]);
        $feeActual = round($gross - (float) $line->net_amount, 2);
        if ($feeActual < 0) $feeActual = 0;
        $feeConfig = $this->computeConfigFee($config, $gross);
        $feeDiff   = round($feeActual - $prebooked, 2);

        $line->update([
            'sales_invoice_id' => $invoice->id,
            'is_matched'       => true,
            'gross_amount'     => $gross,
            'fee_prebooked'    => $prebooked,
            'fee_actual'       => $feeActual,
            'fee_config'       => $feeConfig,
            'fee_diff'         => $feeDiff,
            'note'             => null,
        ]);
    }

    /**
     * Tentukan gross (nilai jual penuh) & fee yang SUDAH dibukukan di faktur.
     * - Ada faktur: gross = grand_total + marketplace_fee (= subtotal, nilai jual sebelum
     *   biaya admin marketplace). prebooked = marketplace_fee (Biaya Admin yg sudah masuk jurnal faktur).
     * - Tanpa faktur: gross = net (asumsi tanpa biaya, order pra-ERP), prebooked = 0.
     * Return [gross, prebooked].
     */
    protected function resolveGross(?SalesInvoice $invoice, array $row): array
    {
        if ($invoice) {
            $prebooked = (float) ($invoice->marketplace_fee ?? 0);
            $gross = round((float) $invoice->grand_total + $prebooked, 2);
            return [$gross, $prebooked];
        }
        $gross = (float) ($row['gross_amount'] ?? 0);
        if ($gross <= 0) $gross = (float) ($row['net_amount'] ?? 0);
        return [$gross, 0.0];
    }

    /**
     * Tanggal posting jurnal rekonsiliasi = TANGGAL DOKUMEN rekonsiliasi (bulan berjalan
     * saat rekonsiliasi dikerjakan), BUKAN di-backdate ke bulan transaksi.
     * Contoh: transaksi Juni yang direkonsiliasi 2 Juli → jurnal masuk periode Juli.
     * Tujuan: buku bulan lalu yang mungkin sudah ditutup tidak ikut berubah.
     */
    protected function resolvePostingDate(MarketplaceSettlement $ms): Carbon
    {
        return Carbon::parse($ms->date)->startOfDay();
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
     * Lookup SalesInvoice via sales_order.customer_po_number, TOLERAN prefix Jubelio.
     *
     * ERP menyimpan PO dengan format Jubelio:
     *   Shopee               → "SP-<order>"                (tanpa store id)
     *   TikTok/Tokopedia/Laz → "TT-/TP-/LZ-<order>-<store>"  (ada store id di belakang)
     * Sedangkan laporan settlement dari dashboard marketplace hanya berisi <order> murni
     * (untuk TikTok/Tokopedia/Lazada = segmen TENGAH antar strip). Maka kita normalkan
     * kedua sisi ke "core" lalu cocokkan.
     *
     * $customerIds boleh berisi >1 customer (marketplace kembar, mis. TikTok+Tokopedia).
     * Return invoice posted (atau draft kalau tidak ada posted), atau null kalau tidak ketemu.
     */
    protected function matchInvoiceByOrderRef(string $orderRef, array|int $customerIds): ?SalesInvoice
    {
        $customerIds = array_values(array_unique(array_filter(array_map('intval', (array) $customerIds))));
        if (empty($customerIds)) return null;

        $orderRef = trim($orderRef);
        $core = $this->normalizeOrderRef($orderRef);

        $base = DB::table('sales_orders')->whereIn('customer_id', $customerIds);

        // 1. Exact match dulu (kompat mundur / order_ref yang sudah lengkap dgn prefix).
        $orderId = (clone $base)->where('customer_po_number', $orderRef)->value('id');

        // 2. Normalized match: cocokkan core ke "…-CORE" (Shopee) atau "…-CORE-STOREID" (lainnya).
        if (!$orderId && $core !== '') {
            $esc = addcslashes($core, '%_\\');
            $orderId = (clone $base)->where(function ($w) use ($esc) {
                $w->where('customer_po_number', 'like', "%-{$esc}")      // PREFIX-CORE
                  ->orWhere('customer_po_number', 'like', "%-{$esc}-%"); // PREFIX-CORE-STOREID
            })->orderByDesc('id')->value('id');
        }

        if (!$orderId) return null;

        return SalesInvoice::where('sales_order_id', $orderId)
            ->orderByRaw("FIELD(status, 'posted', 'paid', 'draft', 'void')")
            ->first();
    }

    /**
     * Normalkan nomor pesanan ke "core" marketplace: buang prefix Jubelio (SP-/TP-/TT-/LZ-/…)
     * lalu ambil segmen pertama antar strip (buang store-id Jubelio di belakang).
     * Contoh:
     *   "SP-26062520FFP68B"              → "26062520FFP68B"
     *   "TP-584682328694097503-127339"   → "584682328694097503"
     *   "LZ-2778098093064322-127336"     → "2778098093064322"
     *   "584682328694097503" (bare)      → "584682328694097503"
     */
    protected function normalizeOrderRef(?string $ref): string
    {
        $ref = trim((string) $ref);
        if ($ref === '') return '';
        // Buang prefix marketplace Jubelio: 2-4 huruf diikuti strip.
        $ref = preg_replace('/^[A-Za-z]{2,4}-/', '', $ref);
        // Ambil segmen pertama; buang store-id Jubelio (mis. "-127339") di belakang.
        $dash = strpos($ref, '-');
        if ($dash !== false) $ref = substr($ref, 0, $dash);
        return trim($ref);
    }

    /**
     * Customer yang dianggap satu marketplace untuk keperluan matching.
     * Config yang BERBAGI akun wallet + hold di-treat sebagai marketplace kembar
     * (mis. TikTok Shop & Tokopedia pasca-merger → cari invoice di kedua customer).
     */
    protected function relatedCustomerIds(MarketplaceConfig $config): array
    {
        if (!$config->account_wallet_id || !$config->account_receivable_hold_id) {
            return array_filter([$config->customer_id]);
        }
        return MarketplaceConfig::where('is_active', 1)
            ->where('account_wallet_id', $config->account_wallet_id)
            ->where('account_receivable_hold_id', $config->account_receivable_hold_id)
            ->pluck('customer_id')
            ->push($config->customer_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Alias label marketplace (dari kolom Excel AI) → config.
     * TikTok & Tokopedia satu sumber pasca-merger: label "tiktok"/"tiktok_tokopedia"
     * diarahkan ke config Tokopedia (matching tetap lintas customer via relatedCustomerIds).
     */
    protected function marketplaceAliases(MarketplaceConfig $cfg): array
    {
        $base = $this->resolveMarketplaceCode($cfg);
        $groups = [
            'shopee'     => ['shopee'],
            'lazada'     => ['lazada'],
            'blibli'     => ['blibli'],
            'tokopedia'  => ['tokopedia', 'tiktok', 'tiktokshop', 'tiktok_tokopedia', 'tiktoktokopedia'],
            'tiktokshop' => ['tiktokshop'],
        ];
        return $groups[$base] ?? array_filter([$base]);
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
