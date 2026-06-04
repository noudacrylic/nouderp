<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Core\Accounting\Account;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Models\InventoryAccountSetting;
use App\Models\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Accounting\OpeningBalanceService;

class OpeningBalanceController extends Controller
{
    public function index(Request $request)
    {
        $q        = trim((string) $request->get('q', ''));
        $dateFrom = $request->get('date_from');
        $dateTo   = $request->get('date_to');
        $type     = $request->get('type');         // increase|decrease (akun)
        $tipe     = $request->get('tipe');         // akun|produk (kind row)

        $rows = collect();

        // ---- AKUN (journal manual saldo awal — exclude FA-driven) ----
        if ($tipe !== 'produk') {
            $jq = Journal::where('is_initial_balance', true)
                ->where('reference_type', 'opening_balance')
                ->where('status', '!=', 'void')
                ->with(['lines.account']);
            if ($dateFrom) $jq->whereDate('date', '>=', $dateFrom);
            if ($dateTo)   $jq->whereDate('date', '<=', $dateTo);
            if ($q !== '') {
                $jq->where(function ($w) use ($q) {
                    $w->where('description', 'like', "%{$q}%")
                      ->orWhereHas('lines.account', function ($a) use ($q) {
                          $a->where('name', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%");
                      });
                });
            }
            if (in_array($type, ['increase', 'decrease'], true)) {
                $jq->whereHas('lines', function ($l) use ($type) {
                    $l->whereHas('account', fn($a) => $a->where('type', '!=', 'equity'));
                    $type === 'increase' ? $l->where('debit', '>', 0) : $l->where('credit', '>', 0);
                });
            }

            foreach ($jq->orderBy('date', 'desc')->get() as $journal) {
                $main = $journal->lines->first(fn($line) => optional($line->account)->type !== 'equity');
                if (!$main) continue;
                $rows->push((object) [
                    'kind'        => 'akun',
                    'date'        => \Carbon\Carbon::parse($journal->date),
                    'item_title'  => $main->account->name ?? 'Unknown',
                    'item_sub'    => $main->account->code ?? '',
                    'detail'      => null,
                    'qty'         => null,
                    'cost'        => null,
                    'debit'       => (float) ($main->debit ?? 0),
                    'credit'      => (float) ($main->credit ?? 0),
                    'description' => $journal->description,
                    'edit_url'    => route('accounts.opening-balance.edit', $journal->id),
                    'edit_label'  => 'Edit',
                    'delete_url'  => route('accounts.opening-balance.destroy', $journal->id),
                    'ledger_url'  => $main->account_id ? route('accounts.show', $main->account_id) : null,
                    'sort_key'    => $journal->date . '-j-' . $journal->id,
                ]);
            }
        }

        // ---- PRODUK (inventory_ledgers transaction_type='opening') ----
        if ($tipe !== 'akun') {
            $lq = InventoryLedger::where('transaction_type', 'opening')
                ->with(['product', 'warehouse']);
            if ($dateFrom) $lq->whereDate('created_at', '>=', $dateFrom);
            if ($dateTo)   $lq->whereDate('created_at', '<=', $dateTo);
            if ($q !== '') {
                $lq->where(function ($w) use ($q) {
                    $w->whereHas('product', function ($p) use ($q) {
                        $p->where('sku', 'like', "%{$q}%")
                          ->orWhere('name', 'like', "%{$q}%");
                    })->orWhereHas('warehouse', fn($wh) => $wh->where('name', 'like', "%{$q}%"));
                });
            }

            foreach ($lq->orderBy('created_at', 'desc')->get() as $ledger) {
                $cost  = (float) ($ledger->hpp_per_unit ?? 0);
                $qty   = (float) ($ledger->qty_in ?? 0);
                $value = $qty * $cost;

                if (in_array($type, ['increase', 'decrease'], true) && $type === 'decrease') {
                    continue; // produk opening = selalu debit/increase
                }

                $rows->push((object) [
                    'kind'        => 'produk',
                    'date'        => $ledger->created_at,
                    'item_title'  => $ledger->product->name ?? '(produk dihapus)',
                    'item_sub'    => $ledger->product->sku ?? '',
                    'detail'      => $ledger->warehouse->name ?? '-',
                    'qty'         => $qty,
                    'cost'        => $cost,
                    'debit'       => $value,
                    'credit'      => 0.0,
                    'description' => 'Saldo awal stok ' . ($ledger->product->sku ?? '') .
                                     ' @ ' . ($ledger->warehouse->name ?? '-') .
                                     ' (' . rtrim(rtrim(number_format($qty, 4, ',', '.'), '0'), ',') .
                                     ' × ' . number_format($cost, 0, ',', '.') . ')',
                    'edit_url'    => $ledger->product_id ? route('inventory.products.setup', $ledger->product_id) : null,
                    'edit_label'  => 'Edit di Produk',
                    'delete_url'  => null,
                    'ledger_url'  => null,
                    'sort_key'    => $ledger->created_at?->format('Y-m-d H:i:s') . '-l-' . $ledger->id,
                ]);
            }
        }

        $journals = $rows->sortByDesc('sort_key')->values();

        return view('erp.accounts.opening_balance.index', compact('journals'));
    }

    public function create()
    {
        $accounts = $this->eligibleAccounts();
        $existingByAccount = $this->existingBalancesByAccount($accounts->pluck('id')->all());

        return view('erp.accounts.opening_balance.create', [
            'accounts'          => $accounts,
            'existingByAccount' => $existingByAccount,
            'journal'           => null,
            'mainLine'          => null,
        ]);
    }

    public function store(Request $request, OpeningBalanceService $service)
    {
        $data = $request->validate([
            'account_id'  => 'required|exists:accounts,id',
            'amount'      => 'required|numeric|min:0.01',
            'date'        => 'required|date',
            'description' => 'nullable|string',
        ]);

        try {
            $service->handle($data);
            return redirect(list_url('accounts.opening-balance.index'))
                ->with('success', 'Saldo awal akun berhasil disimpan.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menyimpan: ' . $e->getMessage())->withInput();
        }
    }

    public function edit(Journal $journal)
    {
        $this->guardManualJournal($journal);

        $journal->load(['lines.account']);
        $mainLine = $journal->lines->first(fn($line) => optional($line->account)->type !== 'equity');

        if (!$mainLine) {
            return redirect()->route('accounts.opening-balance.index')
                ->with('error', 'Jurnal tidak valid: tidak ada line akun non-equity.');
        }

        $accounts = $this->eligibleAccounts();
        $existingByAccount = $this->existingBalancesByAccount($accounts->pluck('id')->all());

        return view('erp.accounts.opening_balance.edit', [
            'journal'           => $journal,
            'mainLine'          => $mainLine,
            'accounts'          => $accounts,
            'existingByAccount' => $existingByAccount,
        ]);
    }

    public function update(Request $request, Journal $journal, OpeningBalanceService $service)
    {
        $this->guardManualJournal($journal);

        $data = $request->validate([
            'amount'      => 'required|numeric|min:0.01',
            'date'        => 'required|date',
            'description' => 'nullable|string',
        ]);

        $journal->load('lines.account');
        $mainLine = $journal->lines->first(fn($line) => optional($line->account)->type !== 'equity');
        if (!$mainLine) {
            return back()->with('error', 'Jurnal tidak valid: tidak ada line akun non-equity.');
        }

        try {
            $service->handle(array_merge($data, ['account_id' => $mainLine->account_id]));
            return redirect(list_url('accounts.opening-balance.index'))
                ->with('success', 'Saldo awal akun berhasil diperbarui.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal memperbarui: ' . $e->getMessage())->withInput();
        }
    }

    public function destroy(Journal $journal, OpeningBalanceService $service)
    {
        $this->guardManualJournal($journal);

        try {
            $service->destroy($journal);
            return redirect(list_url('accounts.opening-balance.index'))
                ->with('success', 'Saldo awal akun berhasil dihapus.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menghapus: ' . $e->getMessage());
        }
    }

    protected function guardManualJournal(Journal $journal): void
    {
        abort_unless(
            $journal->is_initial_balance && $journal->reference_type === 'opening_balance',
            404
        );
    }

    protected function eligibleAccounts()
    {
        return Account::where('type', 'asset')
            ->where(function ($q) {
                $q->where('account_category', 'cash')
                  ->orWhere('account_category', 'cash_equivalent');
            })
            ->orderBy('code')
            ->get();
    }

    /** map account_id => ['journal_id' => x, 'amount' => y] kalau sudah punya saldo awal manual */
    protected function existingBalancesByAccount(array $accountIds): array
    {
        if (empty($accountIds)) return [];

        $lines = JournalLine::whereIn('account_id', $accountIds)
            ->whereHas('journal', function ($q) {
                $q->where('is_initial_balance', true)
                  ->where('reference_type', 'opening_balance')
                  ->where('status', '!=', 'void');
            })
            ->get(['account_id', 'journal_id', 'debit', 'credit']);

        $map = [];
        foreach ($lines as $line) {
            $amount = $line->debit > 0 ? (float) $line->debit : (float) $line->credit;
            if ($amount <= 0) continue;
            $map[$line->account_id] = [
                'journal_id' => $line->journal_id,
                'amount'     => $amount,
            ];
        }
        return $map;
    }

    /**
     * Download template Excel untuk import saldo awal stok produk.
     * Format: 4 kolom — sku, warehouse_name, qty, cost.
     */
    public function productsImportTemplate()
    {
        $ss    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Saldo Awal Produk');

        $sheet->fromArray(['sku', 'warehouse_name', 'qty', 'cost'], null, 'A1');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FEF3C7');

        $sheet->fromArray([
            ['PRD0001', 'Utama', 10,  50000],
            ['PRD0002', 'Utama', 5.5, 75000],
        ], null, 'A2');

        foreach (['A' => 18, 'B' => 22, 'C' => 12, 'D' => 14] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->getStyle('C:C')->getNumberFormat()->setFormatCode('#,##0.####');
        $sheet->getStyle('D:D')->getNumberFormat()->setFormatCode('#,##0');

        $sheet->setCellValue('A6', 'Petunjuk:');
        $sheet->setCellValue('A7', '- sku: kode produk yang sudah terdaftar (wajib).');
        $sheet->setCellValue('A8', '- warehouse_name: nama gudang persis seperti di master gudang (case-insensitive).');
        $sheet->setCellValue('A9', '- qty: jumlah saldo awal stok (boleh desimal).');
        $sheet->setCellValue('A10', '- cost: harga pokok per unit (Rp). Boleh 0 untuk service/non-stock.');
        $sheet->setCellValue('A11', '- Hapus 2 contoh di atas sebelum isi data riil.');
        $sheet->setCellValue('A12', '- Jika produk+gudang sudah punya saldo awal, akan di-update (overwrite).');
        $sheet->setCellValue('A13', '- Periode akuntansi bulan ini harus sudah dibuka.');
        $sheet->getStyle('A6')->getFont()->setBold(true);
        $sheet->getStyle('A6:A13')->getFont()->setItalic(true);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'template-saldo-awal-produk.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Bulk import saldo awal stok produk dari Excel.
     * Re-use InventoryEngine::opening / updateOpening + journal OB-{uniqid}
     * — bentuk identik dengan ProductController::postOpeningBalance.
     */
    public function productsImport(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal baca file: ' . $e->getMessage());
        }

        if (count($rows) < 2) {
            return back()->with('error', 'File kosong atau tidak ada baris data (perlu header + minimal 1 data).');
        }

        array_shift($rows); // skip header

        $setting = InventoryAccountSetting::first();
        if (!$setting || !$setting->inventory_asset_account_id || !$setting->opening_balance_account_id) {
            return back()->with('error', 'Akun Inventory Asset & Opening Balance belum di-set di Pengaturan Inventory.');
        }

        $period = AccountingPeriod::where('status', 'open')
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->first()
            ?? AccountingPeriod::where('status', 'open')
                ->where('year', now()->year)
                ->where('month', now()->month)
                ->first();

        if (!$period) {
            return back()->with('error', 'Periode akuntansi aktif tidak ditemukan untuk bulan ini. Buka periode dulu di Accounting → Period.');
        }

        // Pre-load lookups
        $skus       = collect($rows)->map(fn($r) => trim((string) ($r[0] ?? '')))->filter()->unique()->values();
        $whNames    = collect($rows)->map(fn($r) => trim((string) ($r[1] ?? '')))->filter()->unique()->values();
        $productMap = $skus->isEmpty()
            ? collect()
            : Product::whereIn('sku', $skus)->get()->keyBy(fn($p) => strtolower($p->sku));
        $whMap = $whNames->isEmpty()
            ? collect()
            : Warehouse::whereIn(DB::raw('LOWER(name)'), $whNames->map(fn($n) => strtolower($n))->all())
                ->get()->keyBy(fn($w) => strtolower($w->name));

        $engine        = app(InventoryEngine::class);
        $inventoryAcct = Account::find($setting->inventory_asset_account_id);
        $openingAcct   = Account::find($setting->opening_balance_account_id);

        $ok = 0; $skipped = [];

        foreach ($rows as $idx => $row) {
            $rowNum = $idx + 2;
            $sku    = strtolower(trim((string) ($row[0] ?? '')));
            $whName = strtolower(trim((string) ($row[1] ?? '')));
            $qty    = (float) clean_number($row[2] ?? 0);
            $cost   = (float) clean_number($row[3] ?? 0);

            if ($sku === '' && $whName === '' && $qty == 0 && $cost == 0) continue; // baris kosong

            if ($sku === '')                { $skipped[] = "Baris {$rowNum}: SKU kosong";                       continue; }
            if (!isset($productMap[$sku]))  { $skipped[] = "Baris {$rowNum}: SKU '{$row[0]}' tidak ditemukan";  continue; }
            if ($whName === '')             { $skipped[] = "Baris {$rowNum}: warehouse_name kosong";            continue; }
            if (!isset($whMap[$whName]))    { $skipped[] = "Baris {$rowNum}: gudang '{$row[1]}' tidak ditemukan"; continue; }
            if ($qty <= 0)                  { $skipped[] = "Baris {$rowNum}: qty harus > 0";                    continue; }
            if ($cost < 0)                  { $skipped[] = "Baris {$rowNum}: cost tidak boleh negatif";         continue; }

            $product   = $productMap[$sku];
            $warehouse = $whMap[$whName];
            $value     = $qty * $cost;

            try {
                DB::transaction(function () use ($engine, $product, $warehouse, $qty, $cost, $value, $period, $inventoryAcct, $openingAcct) {
                    $product->last_cost = $cost;
                    $product->save();

                    $exists = InventoryLedger::where([
                        'product_id'       => $product->id,
                        'transaction_type' => 'opening',
                        'transaction_id'   => $product->id,
                    ])->exists();

                    if ($exists) {
                        $engine->updateOpening($product->id, $warehouse->id, $qty, $cost, $product->id);
                    } else {
                        $engine->opening($product->id, $warehouse->id, $qty, $cost, $product->id);
                    }

                    $journal = Journal::where([
                        'reference_type' => 'product',
                        'reference_id'   => $product->id,
                    ])->where('journal_number', 'like', 'OB-%')->first();

                    if ($journal) {
                        $journal->update(['period_id' => $period->id, 'date' => now()]);
                        JournalLine::where('journal_id', $journal->id)->delete();
                    } else {
                        $journal = Journal::create([
                            'journal_number'  => 'OB-' . uniqid(),
                            'date'            => now(),
                            'period_id'       => $period->id,
                            'reference_type'  => 'product',
                            'reference_id'    => $product->id,
                            'description'     => 'Opening balance product ' . $product->sku,
                            'status'          => 'posted',
                        ]);
                    }

                    JournalLine::create([
                        'journal_id' => $journal->id,
                        'account_id' => $inventoryAcct->id,
                        'debit'      => $value,
                        'credit'     => 0,
                    ]);
                    JournalLine::create([
                        'journal_id' => $journal->id,
                        'account_id' => $openingAcct->id,
                        'debit'      => 0,
                        'credit'     => $value,
                    ]);
                });
                $ok++;
            } catch (\Throwable $e) {
                $skipped[] = "Baris {$rowNum} ({$row[0]}): " . $e->getMessage();
            }
        }

        $msg = "Saldo awal produk berhasil di-import: {$ok} baris.";
        if ($skipped) {
            $preview = array_slice($skipped, 0, 10);
            $more    = count($skipped) - count($preview);
            $msg .= ' Dilewati: ' . count($skipped) . ' baris — ' . implode(' | ', $preview);
            if ($more > 0) $msg .= " … dan {$more} lagi";
        }

        return redirect(route('accounts.opening-balance.index', ['tipe' => 'produk']))
            ->with($ok > 0 ? 'success' : 'error', $msg);
    }
}
