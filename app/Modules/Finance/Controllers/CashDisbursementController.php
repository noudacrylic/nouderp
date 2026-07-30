<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Finance\Services\CashDisbursementService;
use App\Core\Accounting\Account;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\CustomerOverpayment;
use Illuminate\Http\Request;

class CashDisbursementController extends Controller
{
    public function __construct(protected CashDisbursementService $service) {}

    public function indexGeneral(Request $request)
    {
        $items = $this->baseIndexQuery($request, 'general')
            ->with(['cashAccount'])
            ->paginate(25)->withQueryString();
        return view('erp.finance.cash-disbursements.index-general', compact('items'));
    }

    public function indexFreight(Request $request)
    {
        $items = $this->baseIndexQuery($request, 'freight')
            ->with(['cashAccount', 'lines.salesInvoice.customer'])
            ->paginate(25)->withQueryString();
        return view('erp.finance.cash-disbursements.index-freight', compact('items'));
    }

    public function indexRefund(Request $request)
    {
        $items = $this->baseIndexQuery($request, 'customer_refund')
            ->with(['cashAccount', 'customer'])
            ->paginate(25)->withQueryString();
        return view('erp.finance.cash-disbursements.index-refund', compact('items'));
    }

    /**
     * Shared filter+search builder untuk 3 index method.
     */
    protected function baseIndexQuery(Request $request, string $type)
    {
        return CashDisbursement::query()
            ->where('type', $type)
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->date_to, fn($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('number', 'like', "%{$term}%")
                      ->orWhere('payee', 'like', "%{$term}%")
                      ->orWhere('reference', 'like', "%{$term}%")
                      ->orWhereHas('lines', function ($l) use ($term) {
                          $l->where('description', 'like', "%{$term}%");
                      });
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    public function create(Request $request)
    {
        $type = $request->get('type', 'general');
        return view('erp.finance.cash-disbursements.create', $this->formData($type));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        try {
            $cd = $this->service->createDraft($data);
            $action = $request->input('_after_save', '');
            if ($action === 'post') {
                $this->service->post($cd);
                return redirect(list_url($cd->listRouteName()))
                    ->with('success', 'Pengeluaran ' . $cd->number . ' dibuat & POSTED.');
            }
            return redirect(list_url($cd->listRouteName()))
                ->with('success', 'Pengeluaran ' . $cd->number . ' dibuat sebagai draft.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $cd = CashDisbursement::with(['cashAccount', 'customer', 'lines.account', 'lines.salesInvoice'])->findOrFail($id);
        return view('erp.finance.cash-disbursements.show', compact('cd'));
    }

    public function edit($id)
    {
        $cd = CashDisbursement::with('lines')->findOrFail($id);
        if (!$cd->isDraft()) {
            return redirect()->route('finance.cash-bank.disbursements.show', $cd->id)
                ->with('error', 'Hanya draft yang bisa diedit.');
        }
        return view('erp.finance.cash-disbursements.edit', array_merge($this->formData($cd->type, $cd->customer_id), compact('cd')));
    }

    public function update(Request $request, $id)
    {
        $cd = CashDisbursement::findOrFail($id);
        $data = $this->validateData($request);
        try {
            $this->service->update($cd, $data);
            $action = $request->input('_after_save', '');
            if ($action === 'post') {
                $this->service->post($cd->refresh());
                return redirect(list_url($cd->listRouteName()))
                    ->with('success', 'Pengeluaran ' . $cd->number . ' diupdate & POSTED.');
            }
            return redirect(list_url($cd->listRouteName()))
                ->with('success', 'Pengeluaran ' . $cd->number . ' diupdate (draft).');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $cd = CashDisbursement::findOrFail($id);
        if (!$cd->isDraft()) {
            return back()->with('error', 'Hanya draft yang bisa dihapus.');
        }
        $cd->lines()->delete();
        $cd->delete();
        return back()->with('success', 'Draft dihapus.');
    }

    public function post($id)
    {
        $cd = CashDisbursement::findOrFail($id);
        try {
            $this->service->post($cd);
            return redirect(list_url($cd->listRouteName()))
                ->with('success', 'Dokumen ' . $cd->number . ' POSTED.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $cd = CashDisbursement::findOrFail($id);
        if (!$cd->canBeVoided()) {
            return back()->with('error', 'Tidak bisa di-void.');
        }
        try {
            $this->service->void($cd);
            return back()->with('success', 'Dokumen ' . $cd->number . ' di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function print($id)
    {
        $cd = CashDisbursement::with(['cashAccount', 'customer', 'lines.account', 'lines.salesInvoice'])->findOrFail($id);
        return view('erp.finance.cash-disbursements.print', compact('cd'));
    }

    /**
     * AJAX endpoint: invoice yang punya shipping_cost > 0, belum dibayar ongkirnya,
     * lengkap dengan delivery info (no SJ + resi). Untuk freight type.
     */
    public function freightInvoices(Request $request)
    {
        // Invoice yang sudah ada freight line di CD draft/posted (belum void) tidak ditampilkan
        $paidInvoiceIds = \App\Modules\Finance\Models\CashDisbursementLine::query()
            ->whereNotNull('sales_invoice_id')
            ->whereHas('disbursement', fn($q) => $q->where('type', 'freight')->whereIn('status', ['draft', 'posted']))
            ->pluck('sales_invoice_id')
            ->all();

        // Saat edit, izinkan invoice yang sudah dipakai oleh CD sendiri
        if ($excludeCdId = $request->integer('exclude_cd_id')) {
            $ownInvoiceIds = \App\Modules\Finance\Models\CashDisbursementLine::query()
                ->where('cash_disbursement_id', $excludeCdId)
                ->whereNotNull('sales_invoice_id')
                ->pluck('sales_invoice_id')
                ->all();
            $paidInvoiceIds = array_diff($paidInvoiceIds, $ownInvoiceIds);
        }

        $rows = SalesInvoice::with(['customer', 'delivery'])
            ->whereIn('status', ['posted', 'paid'])
            ->where('shipping_cost', '>', 0)
            ->whereNotIn('id', $paidInvoiceIds)
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('invoice_number', 'like', "%{$term}%")
                      ->orWhereHas('customer', fn($c) => $c->where('name', 'like', "%{$term}%"))
                      ->orWhereHas('delivery', function ($d) use ($term) {
                          $d->where('delivery_number', 'like', "%{$term}%")
                            ->orWhere('tracking_number', 'like', "%{$term}%");
                      });
                });
            })
            ->orderByDesc('invoice_date')
            ->limit(200)
            ->get();

        return response()->json($rows->map(fn($i) => [
            'id'              => $i->id,
            'invoice_number'  => $i->invoice_number,
            'invoice_date'    => optional($i->invoice_date)->format('Y-m-d'),
            'customer'        => $i->customer->name ?? '-',
            'shipping_cost'   => (float) $i->shipping_cost,
            'delivery_number' => $i->delivery->delivery_number ?? null,
            'tracking_number' => $i->delivery->tracking_number ?? null,
            'courier_name'    => $i->delivery->courier_name ?? null,
        ]));
    }

    /**
     * AJAX: parse Excel ongkir aktual. Format 2 kolom posisional:
     *   - Kolom A (row 1 header dilewati): pengenal — bisa No Invoice, No DO/SJ, atau No Resi
     *   - Kolom B: bayar aktual (angka, format Indonesia atau US OK)
     *
     * Match strategy per row:
     *   1. invoice_number == identifier
     *   2. delivery.delivery_number == identifier
     *   3. delivery.tracking_number == identifier
     * Yang ketemu duluan dipakai. Kalau invoice sama disebut 2× via identifier berbeda,
     * baris kedua di-skip.
     */
    public function freightImport(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath());
            // formatData=false → ambil RAW value (numeric/string asli), JANGAN return formatted string.
            // formatted="15,000" akan salah di-parse clean_number (comma=decimal di Indo), tapi
            // raw=15000 (numeric) di-parse benar.
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Gagal baca file: ' . $e->getMessage()], 422);
        }

        if (count($rows) < 2) {
            return response()->json(['error' => 'File kosong atau tidak ada baris data (perlu header + minimal 1 data).'], 422);
        }

        array_shift($rows);  // skip header row

        // Kumpulkan semua identifier dari kolom 0
        $identifiers = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row[0] ?? ''));
            if ($id !== '') $identifiers[] = $id;
        }
        $identifiers = array_values(array_unique($identifiers));

        if (empty($identifiers)) {
            return response()->json(['error' => 'Tidak ada baris data dengan pengenal terisi di kolom A.'], 422);
        }

        // Filter invoice yang sudah ada di CD freight aktif lain
        $paidInvoiceIds = \App\Modules\Finance\Models\CashDisbursementLine::query()
            ->whereNotNull('sales_invoice_id')
            ->whereHas('disbursement', fn($q) => $q->where('type', 'freight')->whereIn('status', ['draft', 'posted']))
            ->pluck('sales_invoice_id')->all();
        if ($excludeCdId = $request->integer('exclude_cd_id')) {
            $ownInvoiceIds = \App\Modules\Finance\Models\CashDisbursementLine::where('cash_disbursement_id', $excludeCdId)
                ->whereNotNull('sales_invoice_id')->pluck('sales_invoice_id')->all();
            $paidInvoiceIds = array_diff($paidInvoiceIds, $ownInvoiceIds);
        }

        // Cari invoice match — invoice_number ATAU delivery_number ATAU tracking_number
        $invoices = SalesInvoice::with(['customer', 'delivery'])
            ->where(function ($q) use ($identifiers) {
                $q->whereIn('invoice_number', $identifiers)
                  ->orWhereHas('delivery', function ($d) use ($identifiers) {
                      $d->whereIn('delivery_number', $identifiers)
                        ->orWhereIn('tracking_number', $identifiers);
                  });
            })
            ->whereIn('status', ['posted', 'paid'])
            ->where('shipping_cost', '>', 0)
            ->whereNotIn('id', $paidInvoiceIds)
            ->get();

        // Build lookup maps
        $byInvoice  = $invoices->keyBy('invoice_number');
        $byDelivery = $invoices->filter(fn($i) => $i->delivery?->delivery_number)
                               ->keyBy(fn($i) => $i->delivery->delivery_number);
        $byResi     = $invoices->filter(fn($i) => $i->delivery?->tracking_number)
                               ->keyBy(fn($i) => $i->delivery->tracking_number);

        $matches        = [];
        $skipped        = [];
        $usedInvoiceIds = [];

        foreach ($rows as $idx => $row) {
            $rowNum     = $idx + 2;  // +1 header, +1 supaya 1-indexed
            $identifier = trim((string) ($row[0] ?? ''));
            $amount     = (float) clean_number($row[1] ?? 0);

            if ($identifier === '') continue;

            $inv = $byInvoice[$identifier] ?? null;
            $via = $inv ? 'invoice' : null;
            if (!$inv) {
                $inv = $byDelivery[$identifier] ?? null;
                $via = $inv ? 'DO' : $via;
            }
            if (!$inv) {
                $inv = $byResi[$identifier] ?? null;
                $via = $inv ? 'resi' : $via;
            }

            if (!$inv) {
                $skipped[] = ['row' => $rowNum, 'identifier' => $identifier, 'reason' => 'tidak cocok dengan no invoice / DO / resi (atau sudah dibayar / shipping_cost=0)'];
                continue;
            }
            if ($amount <= 0) {
                $skipped[] = ['row' => $rowNum, 'identifier' => $identifier, 'reason' => 'bayar aktual harus > 0'];
                continue;
            }
            if (in_array($inv->id, $usedInvoiceIds, true)) {
                $skipped[] = ['row' => $rowNum, 'identifier' => $identifier, 'reason' => "invoice {$inv->invoice_number} sudah disebut di baris sebelumnya"];
                continue;
            }
            $usedInvoiceIds[] = $inv->id;

            $matches[] = [
                'invoice_id'     => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'customer'       => $inv->customer->name ?? '-',
                'amount'         => $amount,
                'matched_via'    => $via,
            ];
        }

        return response()->json([
            'matches' => $matches,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Download template Excel untuk import bayar ongkir.
     * Format: 2 kolom — A = Pengenal (Inv/DO/Resi), B = Bayar Aktual.
     */
    public function freightImportTemplate()
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bayar Ongkir');

        // Header
        $sheet->setCellValue('A1', 'Pengenal (No Invoice / No DO / No Resi)');
        $sheet->setCellValue('B1', 'Bayar Aktual');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        $sheet->getStyle('A1:B1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle('A1:B1')->getFill()->getStartColor()->setRGB('FEF3C7');
        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getStyle('B:B')->getNumberFormat()->setFormatCode('#,##0');

        // Contoh row
        $sheet->setCellValue('A2', 'INV-2026-001');
        $sheet->setCellValue('B2', 15000);
        $sheet->setCellValue('A3', 'SJ-2026-001');
        $sheet->setCellValue('B3', 12500);
        $sheet->setCellValue('A4', 'SPX1234567890');
        $sheet->setCellValue('B4', 18000);

        // Petunjuk
        $sheet->setCellValue('A6', 'Petunjuk:');
        $sheet->setCellValue('A7', '- Kolom A bebas diisi salah satu: No Invoice, No DO/Surat Jalan, atau No Resi.');
        $sheet->setCellValue('A8', '- Kolom B = nominal ongkir aktual yang dibayar ke ekspedisi (angka).');
        $sheet->setCellValue('A9', '- Hapus 3 baris contoh di atas sebelum isi data riil.');
        $sheet->getStyle('A6')->getFont()->setBold(true);
        $sheet->getStyle('A6:A9')->getFont()->setItalic(true);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'template-bayar-ongkir.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * AJAX: balance overpay customer.
     */
    public function customerOverpayBalance($customerId)
    {
        return response()->json([
            'balance' => $this->service->getCustomerOverpayBalance((int) $customerId),
        ]);
    }

    /**
     * Quick-store untuk modal di halaman Rekonsiliasi Bank.
     * Bikin Pengeluaran Umum 1-baris, langsung POSTED, return JSON.
     */
    public function quickStore(Request $request)
    {
        $data = $request->validate([
            'date'               => 'required|date',
            'cash_account_id'    => 'required|exists:accounts,id',
            'expense_account_id' => 'required|exists:accounts,id',
            'amount'             => 'required|numeric|gt:0',
            'description'        => 'nullable|string|max:255',
            'reference'          => 'nullable|string|max:255',
        ]);

        try {
            $cd = $this->service->createDraft([
                'date'            => $data['date'],
                'type'            => 'general',
                'cash_account_id' => $data['cash_account_id'],
                'reference'       => $data['reference'] ?? null,
                // notes dipakai sebagai description cash-line → tampil di rekonsiliasi bank
                'notes'           => $data['description'] ?? null,
                'lines' => [[
                    'account_id'  => $data['expense_account_id'],
                    'amount'      => $data['amount'],
                    'description' => $data['description'] ?? null,
                ]],
            ]);
            $this->service->post($cd);
            return response()->json([
                'success' => true,
                'number'  => $cd->number,
                'message' => 'Pengeluaran ' . $cd->number . ' dibuat & POSTED.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    protected function formData(string $type = 'general', ?int $currentCustomerId = null): array
    {
        $cashAccounts = Account::whereIn('account_category', ['cash', 'cash_equivalent'])
            ->where('is_active', 1)->orderBy('code')->get();

        // Akun debit Pengeluaran Umum: beban + ekuitas (Prive/penarikan modal, dividen
        // dari Laba Ditahan, dll) + hutang (pelunasan pinjaman → Dr Hutang / Cr Kas).
        // Ekuitas berbasis-debit seperti Prive valid di-debit saat kas keluar.
        // Akun hutang bersubledger dikecualikan supaya buku besar tetap cocok
        // dengan modulnya (AP faktur pembelian, uang muka & lebih bayar customer).
        $expenseAccounts = Account::whereIn('type', ['expense', 'equity', 'liability'])
            ->whereNotIn('code', \App\Enums\AccountCodeEnum::SUBLEDGER_LIABILITY_CODES)
            ->where('is_active', 1)
            ->orderByRaw("FIELD(type, 'expense', 'equity', 'liability')")
            ->orderBy('code')->get();

        // Customers dengan saldo overpay (sum dari customer_overpayments).
        // Untuk type=customer_refund: hanya tampilkan yang saldo > 0,
        // plus current customer kalau sedang edit (meskipun saldo-nya 0).
        $customers = Customer::where('is_active', 1)
            ->addSelect([
                'overpay_balance' => \App\Models\CustomerOverpayment::selectRaw('COALESCE(SUM(amount),0)')
                    ->whereColumn('customer_id', 'customers.id'),
            ])
            ->orderBy('name')
            ->get();

        if ($type === 'customer_refund') {
            $customers = $customers->filter(function ($c) use ($currentCustomerId) {
                return (float) ($c->overpay_balance ?? 0) > 0 || $c->id == $currentCustomerId;
            })->values();
        }

        $titipanAccount = Account::where('code', '1203')->first();
        $overpayAccount = Account::where('code', \App\Enums\AccountCodeEnum::CUSTOMER_OVERPAY)->first();

        return compact('type', 'cashAccounts', 'expenseAccounts', 'customers', 'titipanAccount', 'overpayAccount');
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'date'            => 'required|date',
            'type'            => 'required|in:general,freight,customer_refund',
            'cash_account_id' => 'required|exists:accounts,id',
            'customer_id'     => 'nullable|exists:customers,id',
            'payee'           => 'nullable|string|max:255',
            'reference'       => 'nullable|string|max:255',
            'notes'           => 'nullable|string',
            'lines'                          => 'required|array|min:1',
            'lines.*.account_id'             => 'required|exists:accounts,id',
            'lines.*.amount'                 => 'required|numeric|gt:0',
            'lines.*.description'            => 'nullable|string|max:255',
            'lines.*.sales_invoice_id'       => 'nullable|exists:sales_invoices,id',
            'lines.*.customer_overpayment_id'=> 'nullable|exists:customer_overpayments,id',
        ]);
    }

    // ───────────── Biaya API Biteship (upload Excel bulanan) ─────────────

    /** Halaman sub-tab "Biaya API Biteship": form upload + daftar yang sudah tercatat. */
    public function apiCostIndex(Request $request)
    {
        $items = CashDisbursement::where('reference', 'like', \App\Modules\Finance\Services\BiteshipApiCostService::REF_PREFIX . '%')
            ->where('status', '!=', 'void')
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return view('erp.finance.cash-disbursements.api-cost', compact('items'));
    }

    /** Proses upload Excel export "Transaksi API" Biteship → catat biaya per tanggal (idempotent). */
    public function apiCostImport(Request $request, \App\Modules\Finance\Services\BiteshipApiCostService $svc)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
            $summary = $svc->importRows($rows);
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal proses file: ' . $e->getMessage());
        }

        $msg = "{$summary['count_created']} hari dicatat (Rp " . number_format($summary['total_created'], 0, ',', '.') . ')';
        if (count($summary['skipped'])) $msg .= ', ' . count($summary['skipped']) . ' dilewati (sudah ada/0)';
        if (count($summary['errors']))  $msg .= ', ' . count($summary['errors']) . ' error';

        $flash = back()->with($summary['count_created'] > 0 ? 'success' : 'info', $msg);
        if (count($summary['errors'])) {
            $flash->with('error', 'Baris bermasalah: ' . collect($summary['errors'])
                ->map(fn ($e) => $e['date'] . ' (' . $e['reason'] . ')')->take(5)->implode('; '));
        }
        return $flash;
    }
}
