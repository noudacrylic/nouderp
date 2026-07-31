<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\BankReconciliation;
use App\Modules\Finance\Services\BankReconciliationService;
use App\Modules\Finance\Services\BankTransferService;
use App\Core\Accounting\Account;
use App\Core\Journal\JournalLine;
use App\Models\MarketplaceConfig;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BankReconciliationController extends Controller
{
    public function __construct(protected BankReconciliationService $service) {}

    /**
     * Akun kas/bank yang dikelola via Marketplace Settlement (hold + wallet
     * dari MarketplaceConfig aktif). Akun-akun ini di-exclude dari rekonsiliasi
     * bank biasa karena rekonsiliasinya lewat statement marketplace.
     */
    protected function marketplaceManagedAccountIds(): array
    {
        return MarketplaceConfig::where('is_active', 1)
            ->get(['account_receivable_hold_id', 'account_wallet_id'])
            ->flatMap(fn ($c) => array_filter([$c->account_receivable_hold_id, $c->account_wallet_id]))
            ->unique()
            ->values()
            ->all();
    }

    public function index(Request $request)
    {
        $items = BankReconciliation::with('account')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->account_id, fn($q, $a) => $q->where('account_id', $a))
            ->when($request->period_year, fn($q, $y) => $q->where('period_year', $y))
            ->when($request->period_month, fn($q, $m) => $q->where('period_month', $m))
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $excludeIds = $this->marketplaceManagedAccountIds();
        $accounts = Account::where('is_cash_account', 1)
            ->where('is_active', 1)
            ->whereNotIn('id', $excludeIds)
            ->orderBy('code')->get();

        // Per-account status untuk bulan yang dipilih. Default: bulan TERTUA yang masih punya
        // aktivitas kas/bank tapi BELUM selesai direkonsiliasi (per akun+bulan) — supaya bulan
        // lama tetap tampil walau sudah lewat (mis. Juni belum selesai walau sekarang Juli).
        // Draft belum dihitung selesai. Begitu semua akun bulan itu completed → default pindah
        // ke bulan berikutnya / bulan berjalan. Tetap bisa diganti manual via picker.
        $statusMonth = $request->input('status_month');
        if (!$statusMonth || !preg_match('/^\d{4}-\d{2}$/', $statusMonth)) {
            $reconAccountIds = $accounts->pluck('id')->all();

            // (account_id | 'YYYY-MM') yang SUDAH completed.
            $completed = BankReconciliation::where('status', 'completed')
                ->get(['account_id', 'period_year', 'period_month'])
                ->map(fn ($b) => $b->account_id . '|' . sprintf('%04d-%02d', $b->period_year, $b->period_month))
                ->flip();

            // Cari bulan tertua dgn aktivitas jurnal (non-void) di akun rekonsiliasi yang
            // pasangan akun+bulannya belum completed.
            $oldestUnreconciled = null;
            if (!empty($reconAccountIds)) {
                $activity = JournalLine::query()
                    ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                    ->whereIn('journal_lines.account_id', $reconAccountIds)
                    ->where('journals.status', '!=', 'void')
                    ->selectRaw("journal_lines.account_id AS aid, DATE_FORMAT(journals.date, '%Y-%m') AS ym")
                    ->distinct()
                    ->get();
                foreach ($activity as $a) {
                    if (isset($completed[$a->aid . '|' . $a->ym])) continue;
                    if ($oldestUnreconciled === null || $a->ym < $oldestUnreconciled) {
                        $oldestUnreconciled = $a->ym;
                    }
                }
            }
            $statusMonth = $oldestUnreconciled ?: now()->format('Y-m');
        }
        [$sy, $sm] = array_map('intval', explode('-', $statusMonth));

        $latestPerAccount = BankReconciliation::selectRaw('account_id, MAX(CONCAT(LPAD(period_year,4,0),LPAD(period_month,2,0))) AS latest_period')
            ->where('status', 'completed')
            ->groupBy('account_id')
            ->pluck('latest_period', 'account_id');

        $currentMonth = BankReconciliation::where('period_year', $sy)
            ->where('period_month', $sm)
            ->whereIn('status', ['draft', 'completed'])
            ->get(['id', 'number', 'account_id', 'status'])
            ->keyBy('account_id');

        $accountStatuses = $accounts->map(function ($acc) use ($currentMonth, $latestPerAccount) {
            $br = $currentMonth->get($acc->id);
            $latest = $latestPerAccount->get($acc->id);
            return [
                'account'        => $acc,
                'status'         => $br ? $br->status : 'none',
                'br_id'          => $br?->id,
                'br_number'      => $br?->number,
                'latest_period'  => $latest ? substr($latest, 0, 4) . '-' . substr($latest, 4, 2) : null,
            ];
        });

        return view('erp.finance.bank-reconciliations.index', compact(
            'items', 'accounts', 'statusMonth', 'accountStatuses'
        ));
    }

    public function create(Request $request)
    {
        $excludeIds = $this->marketplaceManagedAccountIds();
        $accounts = Account::where('is_cash_account', 1)
            ->where('is_active', 1)
            ->whereNotIn('id', $excludeIds)
            ->orderBy('code')->get();

        // Map: account_id => { 'YYYY-MM' => { id, number, status } }
        $reconciledMap = [];
        BankReconciliation::whereIn('status', ['draft', 'completed'])
            ->get(['id', 'number', 'account_id', 'period_year', 'period_month', 'status'])
            ->each(function ($br) use (&$reconciledMap) {
                $period = sprintf('%04d-%02d', $br->period_year, $br->period_month);
                $reconciledMap[$br->account_id][$period] = [
                    'id'     => $br->id,
                    'number' => $br->number,
                    'status' => $br->status,
                ];
            });

        $prefillAccountId = (int) $request->input('account_id') ?: null;
        $prefillPeriod    = $request->input('period');
        if (!$prefillPeriod || !preg_match('/^\d{4}-\d{2}$/', $prefillPeriod)) {
            $prefillPeriod = now()->format('Y-m');
        }

        return view('erp.finance.bank-reconciliations.create', compact(
            'accounts', 'reconciledMap', 'prefillAccountId', 'prefillPeriod'
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'account_id'        => 'required|exists:accounts,id',
            'period'            => 'required|regex:/^\d{4}-\d{2}$/',
            'statement_balance' => 'nullable|numeric',
            'notes'             => 'nullable|string',
        ]);

        $p = Carbon::createFromFormat('Y-m-d', $data['period'].'-01');
        $data['start_date'] = $p->copy()->startOfMonth()->toDateString();
        $data['end_date']   = $p->copy()->endOfMonth()->toDateString();

        // Guard duplicate: kalau sudah ada draft/completed untuk akun+periode ini, langsung
        // arahkan ke dokumen yang ada (edit kalau draft, show kalau completed).
        $existing = BankReconciliation::where('account_id', $data['account_id'])
            ->where('period_year', $p->year)
            ->where('period_month', $p->month)
            ->whereIn('status', ['draft', 'completed'])
            ->first();
        if ($existing) {
            $route = $existing->isDraft() ? 'finance.cash-bank.reconciliations.edit' : 'finance.cash-bank.reconciliations.show';
            return redirect()->route($route, $existing->id)
                ->with('success', 'Rekonsiliasi ' . $existing->number . ' sudah ada — melanjutkan.');
        }

        try {
            $br = $this->service->createDraft($data);
            return redirect()->route('finance.cash-bank.reconciliations.edit', $br->id)
                ->with('success', 'Rekonsiliasi ' . $br->number . ' dibuat. Silakan mencocokkan transaksi.');
        } catch (\Throwable $e) {
            return redirect(list_url('finance.cash-bank.reconciliations.index'))
                ->with('error', $e->getMessage());
        }
    }

    public function edit($id)
    {
        $br = BankReconciliation::findOrFail($id);
        if (!$br->isDraft()) {
            return redirect()->route('finance.cash-bank.reconciliations.show', $br->id);
        }

        // Sync line — pickup transaksi baru yg dibuat lewat quick-add modal setelah BR dibuat.
        $this->service->syncLines($br);

        $br->load(['account', 'lines.journalLine.journal', 'lines.journalLine.account', 'statementLines']);

        // Laporan pencocokan rekening koran (jika sudah di-upload).
        $statementMatch = $this->service->buildStatementMatch($br);

        // Sortir line kronologis: tanggal jurnal lalu id jurnal lalu id line.
        // Penting untuk perhitungan running balance.
        $br->setRelation('lines', $br->lines->sortBy([
            fn($a, $b) => strcmp($a->journalLine->journal->date, $b->journalLine->journal->date),
            fn($a, $b) => $a->journalLine->journal_id <=> $b->journalLine->journal_id,
            fn($a, $b) => $a->journalLine->id <=> $b->journalLine->id,
        ])->values());

        $matchedSum = 0;
        $unmatchedSum = 0;
        foreach ($br->lines as $l) {
            $jl = $l->journalLine;
            $signed = (float) ($jl->debit ?? 0) - (float) ($jl->credit ?? 0);
            if ($l->is_matched) $matchedSum += $signed;
            else $unmatchedSum += $signed;
        }

        // Akun untuk modal quick-add Pembayaran/Pemasukan.
        $expenseAccounts = Account::where('type', 'expense')
            ->where('is_active', 1)->orderBy('code')->get();
        // Akun kredit untuk modal Pemasukan: pendapatan + hutang (terima pinjaman).
        // Selaras dgn CashReceiptController::formData().
        $revenueAccounts = Account::whereIn('type', ['revenue', 'liability'])
            ->whereNotIn('code', \App\Enums\AccountCodeEnum::SUBLEDGER_LIABILITY_CODES)
            ->where('is_active', 1)
            ->orderByRaw("FIELD(type, 'revenue', 'liability')")
            ->orderBy('code')->get();

        // Akun debit untuk modal + Pengeluaran Umum: beban + ekuitas (Prive/penarikan
        // modal) + hutang (pelunasan pinjaman). Ekuitas berbasis-debit spt Prive valid
        // di-debit saat kas keluar. Selaras dgn CashDisbursementController::formData().
        $disbursementAccounts = Account::whereIn('type', ['expense', 'equity', 'liability'])
            ->whereNotIn('code', \App\Enums\AccountCodeEnum::SUBLEDGER_LIABILITY_CODES)
            ->where('is_active', 1)
            ->orderByRaw("FIELD(type, 'expense', 'equity', 'liability')")
            ->orderBy('code')->get();

        // Akun kas lawan untuk modal quick-add Transfer Bank: semua kas/bank aktif
        // KECUALI rekening yang sedang direkonsiliasi. Akun kelolaan marketplace
        // (Saldo Penjualan Marketplace) SENGAJA diikutkan — pemindahan saldo dari
        // wallet marketplace ke bank adalah transfer antar-kas yang sah.
        $transferAccounts = Account::where('is_cash_account', 1)
            ->where('is_active', 1)
            ->where('id', '!=', $br->account_id)
            ->orderBy('code')->get();
        $defaultAdminFeeAccountId = Account::bankAdminFeeDefaultId();

        return view('erp.finance.bank-reconciliations.edit', compact(
            'br', 'matchedSum', 'unmatchedSum',
            'expenseAccounts', 'revenueAccounts', 'disbursementAccounts',
            'transferAccounts', 'defaultAdminFeeAccountId',
            'statementMatch'
        ));
    }

    /**
     * Upload rekening koran (Excel). Kolom: Tanggal | Keterangan | Uang Masuk | Uang Keluar.
     * Replace data koran lama, lalu kembali ke halaman edit (pencocokan dihitung di edit()).
     */
    public function statementImport(Request $request, $id)
    {
        $br = BankReconciliation::findOrFail($id);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath());
            // formatData=false → ambil RAW value (tanggal serial & angka asli), bukan string terformat.
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal baca file: ' . $e->getMessage());
        }

        if (count($rows) < 2) {
            return back()->with('error', 'File kosong atau tidak ada baris data (perlu header + minimal 1 data).');
        }
        array_shift($rows); // buang baris header

        try {
            $res = $this->service->importStatement($br, $rows);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = "Rekening koran ter-upload: {$res['imported']} baris.";
        if ($res['out_of_period'] > 0) {
            $msg .= " ({$res['out_of_period']} baris tanggalnya di luar periode — tetap dicocokkan berdasarkan nilai.)";
        }
        if (!empty($res['skipped'])) {
            $msg .= ' ' . count($res['skipped']) . ' baris dilewati (tanggal/nilai tidak valid).';
        }

        return redirect()->route('finance.cash-bank.reconciliations.edit', $br->id)->with('success', $msg);
    }

    /**
     * Hapus seluruh data rekening koran yang di-upload untuk BR ini (untuk re-upload).
     */
    public function clearStatement($id)
    {
        $br = BankReconciliation::findOrFail($id);
        $this->service->clearStatement($br);
        return redirect()->route('finance.cash-bank.reconciliations.edit', $br->id)
            ->with('success', 'Data rekening koran dihapus.');
    }

    /**
     * Download template Excel rekening koran.
     * Format: Tanggal | Keterangan | Uang Masuk | Uang Keluar.
     */
    public function statementTemplate()
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekening Koran');

        $sheet->setCellValue('A1', 'Tanggal');
        $sheet->setCellValue('B1', 'Keterangan');
        $sheet->setCellValue('C1', 'Uang Masuk');
        $sheet->setCellValue('D1', 'Uang Keluar');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle('A1:D1')->getFill()->getStartColor()->setRGB('DBEAFE');
        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getStyle('C:D')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode('yyyy-mm-dd');

        // Contoh baris
        $sheet->setCellValue('A2', '2026-06-08');
        $sheet->setCellValue('B2', 'Transfer masuk dari pelanggan');
        $sheet->setCellValue('C2', 960000);
        $sheet->setCellValue('A3', '2026-06-09');
        $sheet->setCellValue('B3', 'Biaya admin bank');
        $sheet->setCellValue('D3', 12500);

        $sheet->setCellValue('A5', 'Petunjuk:');
        $sheet->setCellValue('A6', '- Tanggal format YYYY-MM-DD (mis. 2026-06-08).');
        $sheet->setCellValue('A7', '- Uang Masuk = dana masuk ke rekening; Uang Keluar = dana keluar. Isi salah satu.');
        $sheet->setCellValue('A8', '- Keterangan opsional. Hapus baris contoh & petunjuk sebelum isi data riil.');
        $sheet->getStyle('A5')->getFont()->setBold(true);
        $sheet->getStyle('A5:A8')->getFont()->setItalic(true);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'template-rekening-koran.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function update(Request $request, $id)
    {
        $br = BankReconciliation::findOrFail($id);
        $data = $request->validate([
            'statement_balance'         => 'nullable|numeric',
            'notes'                     => 'nullable|string',
            'matched_journal_line_ids'  => 'nullable|array',
            'matched_journal_line_ids.*'=> 'integer|exists:journal_lines,id',
        ]);
        try {
            $this->service->update($br, $data);
            $action = $request->input('_after_save', '');
            if ($action === 'complete') {
                $this->service->complete($br->refresh());
                return redirect(list_url('finance.cash-bank.reconciliations.index'))
                    ->with('success', 'Rekonsiliasi ' . $br->number . ' diselesaikan.');
            }
            return redirect()->route('finance.cash-bank.reconciliations.edit', $br->id)
                ->with('success', 'Tersimpan sebagai draft.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Quick-add Transfer Antar Bank dari halaman rekonsiliasi. Rekening yang
     * sedang direkonsiliasi menjadi salah satu kaki (sumber saat 'out', tujuan
     * saat 'in'); rekening lawan dipilih di modal. Langsung dibuat & POSTED.
     */
    public function quickStoreTransfer(Request $request, BankTransferService $transferService)
    {
        $data = $request->validate([
            'date'                 => 'required|date',
            'reconciliation_account_id' => 'required|exists:accounts,id',
            'direction'            => 'required|in:in,out',
            'counterparty_account_id'   => 'required|exists:accounts,id|different:reconciliation_account_id',
            'amount'               => 'required|numeric|gt:0',
            'admin_fee'            => 'nullable|numeric|min:0',
            'admin_fee_account_id' => 'nullable|exists:accounts,id',
            'reference'            => 'nullable|string|max:255',
            'notes'                => 'nullable|string|max:255',
        ]);

        // 'out' → kas keluar dari rekening rekonsiliasi (from = rekening ini).
        // 'in'  → kas masuk ke rekening rekonsiliasi (to = rekening ini).
        if ($data['direction'] === 'out') {
            $fromId = $data['reconciliation_account_id'];
            $toId   = $data['counterparty_account_id'];
        } else {
            $fromId = $data['counterparty_account_id'];
            $toId   = $data['reconciliation_account_id'];
        }

        try {
            $bt = $transferService->createDraft([
                'date'                 => $data['date'],
                'from_account_id'      => $fromId,
                'to_account_id'        => $toId,
                'admin_fee_account_id' => $data['admin_fee_account_id'] ?? null,
                'amount'               => $data['amount'],
                'admin_fee'            => $data['admin_fee'] ?? 0,
                'fee_borne_by'         => 'source',
                'reference'            => $data['reference'] ?? null,
                'notes'                => $data['notes'] ?? null,
            ]);
            $transferService->post($bt);
            return response()->json([
                'success' => true,
                'number'  => $bt->number,
                'message' => 'Transfer ' . $bt->number . ' dibuat & POSTED.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    public function show($id)
    {
        $br = BankReconciliation::with(['account', 'lines.journalLine.journal', 'lines.journalLine.account'])->findOrFail($id);
        $br->setRelation('lines', $br->lines->sortBy([
            fn($a, $b) => strcmp($a->journalLine->journal->date, $b->journalLine->journal->date),
            fn($a, $b) => $a->journalLine->journal_id <=> $b->journalLine->journal_id,
            fn($a, $b) => $a->journalLine->id <=> $b->journalLine->id,
        ])->values());
        return view('erp.finance.bank-reconciliations.show', compact('br'));
    }

    public function void($id)
    {
        $br = BankReconciliation::findOrFail($id);
        if (!$br->canBeVoided()) return back()->with('error', 'Tidak bisa di-void.');
        try {
            $this->service->void($br);
            return back()->with('success', 'Rekonsiliasi ' . $br->number . ' di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $br = BankReconciliation::findOrFail($id);
        if (!$br->isDraft()) return back()->with('error', 'Hanya draft yang bisa dihapus.');
        $br->lines()->delete();
        $br->delete();
        return back()->with('success', 'Draft dihapus.');
    }
}
