<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\InventoryAdjustment;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Inventory\ProductStock;
use App\Models\InventoryAccountSetting;
use App\Models\InventoryLedger;
use App\Models\InventoryCostLayer;
use App\Core\Period\AccountingPeriod;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\FifoService;
use App\Core\Inventory\StockLayer;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryAdjustment::with(['warehouse', 'items.product'])->latest();

        $search = trim((string) $request->input('search', $request->input('q', '')));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', '%' . $search . '%')
                    ->orWhere('title', 'like', '%' . $search . '%')
                    ->orWhereHas('items.product', function ($sub) use ($search) {
                        $sub->where('name', 'like', '%' . $search . '%')
                            ->orWhere('sku', 'like', '%' . $search . '%');
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        if ($request->filled('warehouse')) {
            $query->where('warehouse_id', $request->warehouse);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $adjustments = $query->paginate(20)->withQueryString();
        $warehouses = Warehouse::all();

        return view('erp.inventory.adjustments.index', compact('adjustments', 'warehouses'));
    }

    public function edit($id)
    {
        $adjustment = InventoryAdjustment::with('items.product')->findOrFail($id);
        // Sama dgn create: hanya ready & custom/preorder. Tetap sertakan produk yang sudah
        // terpilih di draft ini agar pilihan lama tidak hilang dari dropdown.
        $existingIds = $adjustment->items->pluck('product_id')->filter()->all();
        $products = Product::where(function ($q) use ($existingIds) {
                $q->whereIn('sale_type', ['ready', 'preorder']);
                if ($existingIds) {
                    $q->orWhereIn('id', $existingIds);
                }
            })->orderBy('name')->get();
        $warehouses = Warehouse::all();

        return view('erp.inventory.adjustments.edit', compact('adjustment', 'products', 'warehouses'));
    }

    public function create()
    {
        // Penyesuaian stok hanya untuk barang berstok fisik: ready stok & custom/preorder.
        // Bundle (komponen virtual), jasa, non-stock tidak punya stok untuk di-opname.
        $products        = Product::whereIn('sale_type', ['ready', 'preorder'])->orderBy('name')->get();
        $warehouses      = Warehouse::all();
        // Sertakan akun EQUITY (mis. Modal Awal) di pilihan gain/loss agar penyesuaian
        // SALDO AWAL bisa diarahkan ke Modal Awal (bukan jadi pendapatan/beban di Laba Rugi).
        $incomeAccounts  = \App\Core\Accounting\Account::whereIn('type', ['revenue', 'equity'])->where('is_active', true)->orderBy('code')->get();
        $expenseAccounts = \App\Core\Accounting\Account::whereIn('type', ['expense', 'equity'])->where('is_active', true)->orderBy('code')->get();
        $authName        = auth()->user()?->name ?? '';

        $defaultIncome  = \App\Core\Accounting\Account::where('type', 'revenue')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('name', 'like', '%Pendapatan Selisih Stok%')
                  ->orWhere('name', 'like', '%Selisih Stok%');
            })->first();

        $defaultExpense = \App\Core\Accounting\Account::where('type', 'expense')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('name', 'like', '%Beban Selisih Stok%')
                  ->orWhere('name', 'like', '%Kerugian Selisih%');
            })->first();

        return view(
            'erp.inventory.adjustments.create',
            compact('products', 'warehouses', 'incomeAccounts', 'expenseAccounts', 'authName', 'defaultIncome', 'defaultExpense')
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'purpose'            => 'required|in:stock_opname,perbaikan_rusak',
            'income_account_id'  => 'required_if:purpose,stock_opname|nullable|exists:accounts,id',
            'expense_account_id' => 'required_if:purpose,stock_opname|nullable|exists:accounts,id',
            'date'               => 'required|date',
            'warehouse_id'       => 'required',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required',
            'items.*.actual'     => 'required|numeric|min:0',
        ]);

        if ($request->purpose === 'perbaikan_rusak' && count($request->items) > 1) {
            return back()->withInput()->withErrors(['items' => 'Perbaikan stock rusak hanya boleh 1 produk.']);
        }

        \DB::transaction(function () use ($request) {
            $direction = $request->purpose === 'perbaikan_rusak' ? 'pengurangan' : 'set_stock';

            // Perbaikan: gunakan akun perbaikan dari setting, tidak perlu dipilih user
            $expenseAccountId = $request->purpose === 'perbaikan_rusak'
                ? (\App\Models\InventoryAccountSetting::first()?->repair_account_id)
                : $request->expense_account_id;

            $adjustment = InventoryAdjustment::create([
                'number'             => $this->generateNumber(),
                'purpose'            => $request->purpose,
                'direction'          => $direction,
                'income_account_id'  => $request->purpose === 'stock_opname' ? $request->income_account_id : null,
                'expense_account_id' => $expenseAccountId,
                'type'               => $request->purpose === 'perbaikan_rusak' ? 'Stock Rusak' : 'Stock Opname',
                'date'               => $request->date,
                'warehouse_id'       => $request->warehouse_id,
                'responsible'        => auth()->user()?->name ?? $request->responsible,
                'notes'              => $request->notes,
                'status'             => 'draft',
            ]);

            foreach ($request->items as $item) {
                $stock = ProductStock::where([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $request->warehouse_id
                ])->first();

                $qtySystem = $stock ? $stock->qty_on_hand : 0;
                $diff = $item['actual'] - $qtySystem;

                $adjustment->items()->create([
                    'product_id' => $item['product_id'],
                    'system_qty' => $qtySystem,
                    'actual_qty' => $item['actual'],
                    'diff_qty' => $diff,
                    'notes' => $item['notes'] ?? null
                ]);
            }
        });

        return redirect(list_url('inventory.adjustments.index'))->with('success', 'Adjustment saved as draft');
    }

    private function generateNumber()
    {
        $year = now()->year;
        $count = InventoryAdjustment::whereYear('created_at', $year)->count();
        $next = $count + 1;

        return 'ADJ-' . $year . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'date' => 'required|date',
            'warehouse_id' => 'required',
            'responsible' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required',
            'items.*.actual' => 'required|numeric|min:0'
        ]);

        $adjustment = InventoryAdjustment::findOrFail($id);

        if ($adjustment->status == 'posted') {
            return back()->with('error', 'Cannot edit posted adjustment');
        }

        \DB::transaction(function () use ($request, $adjustment) {
            $adjustment->update([
                'title' => $request->title,
                'type' => $request->type ?? 'Stock Opname',
                'date' => $request->date,
                'warehouse_id' => $request->warehouse_id,
                'responsible' => $request->responsible,
                'notes' => $request->notes,
            ]);

            // Simple approach: delete existing items and re-add
            $adjustment->items()->delete();

            foreach ($request->items as $item) {
                // Fetch fresh system stock based on current warehouse
                $stock = ProductStock::where([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $request->warehouse_id
                ])->first();

                $qtySystem = $stock ? $stock->qty_on_hand : 0;
                $diff = $item['actual'] - $qtySystem;

                $adjustment->items()->create([
                    'product_id' => $item['product_id'],
                    'system_qty' => $qtySystem,
                    'actual_qty' => $item['actual'],
                    'diff_qty' => $diff,
                    'notes' => $item['notes'] ?? null
                ]);
            }
        });

        return redirect(list_url('inventory.adjustments.index'))->with('success', 'Adjustment updated successfully');
    }

    public function post($id)
    {
        $adjustment = InventoryAdjustment::with('items.product')->findOrFail($id);

        if ($adjustment->status === 'posted') {
            return back()->with('error', 'Adjustment has already been posted.');
        }

        $setting = InventoryAccountSetting::first();

        $gainAccountId = $adjustment->income_account_id ?? $setting?->inventory_gain_account_id;

        // Perbaikan: pakai repair_account_id dari setting; stock_opname: pakai expense dari adjustment atau loss setting
        if ($adjustment->purpose === 'perbaikan_rusak') {
            $lossAccountId = $adjustment->expense_account_id ?? $setting?->repair_account_id;
        } else {
            $lossAccountId = $adjustment->expense_account_id ?? $setting?->inventory_loss_account_id;
        }

        if (!$gainAccountId && !$lossAccountId) {
            return back()->with('error', 'Akun penyesuaian belum diatur. Pilih akun saat membuat adjustment.');
        }

        // Get active period
        $period = AccountingPeriod::where('status', 'open')
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->first();

        if (!$period) {
            $period = AccountingPeriod::where('status', 'open')
                ->where('year', now()->year)
                ->where('month', now()->month)
                ->first();
        }

        if (!$period) {
            return back()->with('error', 'Active accounting period not found for this date. Please open a period first.');
        }

        $engine = app(InventoryEngine::class);
        $fifo = app(FifoService::class);

        // Guard: pastikan layer FIFO cukup untuk semua pengurangan sebelum posting,
        // supaya stok kurang memunculkan pesan jelas, bukan Exception 500 di tengah transaksi.
        $stokKurang = [];
        foreach ($adjustment->items as $item) {
            $diff = $item->actual_qty - $item->system_qty;
            if ($diff >= 0) continue;
            $perlu = abs($diff);
            $tersedia = (float) StockLayer::where('product_id', $item->product_id)
                ->where('warehouse_id', $adjustment->warehouse_id)
                ->where('qty_remaining', '>', 0)
                ->sum('qty_remaining');
            if ($tersedia + 0.00001 < $perlu) {
                $stokKurang[] = ($item->product->name ?? ('Produk #' . $item->product_id))
                    . " (perlu {$perlu}, stok FIFO {$tersedia})";
            }
        }
        if (!empty($stokKurang)) {
            return back()->with('error', 'Stok FIFO tidak cukup untuk posting opname: '
                . implode('; ', $stokKurang) . '. Periksa/segarkan data stok produk tersebut.');
        }

        DB::transaction(function () use ($adjustment, $setting, $period, $engine, $fifo, $gainAccountId, $lossAccountId) {

            $totalGain = 0;
            $totalLoss = 0;

            foreach ($adjustment->items as $item) {
                $product = $item->product;
                $system = $item->system_qty;
                $actual = $item->actual_qty;
                $diff = $actual - $system;

                if ($diff == 0) continue;

                $cost = $product->last_cost ?? 0;
                $value = abs($diff) * $cost;

                // 1. Sync Product Stock Total (Legacy support/Direct display)
                $product->stock = $actual;
                $product->save();

                // 2. Ledger & FIFO through Engine
                if ($diff > 0) {
                    // STOCK PLUS
                    $engine->ledger(
                        productId: $product->id,
                        warehouseId: $adjustment->warehouse_id,
                        qtyIn: $diff,
                        qtyOut: 0,
                        type: 'adjustment_in',
                        reference: $adjustment->number,
                        transactionId: $adjustment->id
                    );

                    $fifo->createLayer(
                        productId: $product->id,
                        warehouseId: $adjustment->warehouse_id,
                        qty: $diff,
                        cost: $cost,
                        source: 'adjustment_in',
                        referenceId: $adjustment->id
                    );

                    $totalGain += $value;
                } else {
                    // STOCK MINUS
                    $qtyOut = abs($diff);
                    $engine->ledger(
                        productId: $product->id,
                        warehouseId: $adjustment->warehouse_id,
                        qtyIn: 0,
                        qtyOut: $qtyOut,
                        type: 'adjustment_out',
                        reference: $adjustment->number,
                        transactionId: $adjustment->id
                    );

                    $fifoCost = $fifo->consume(
                        productId: $product->id,
                        warehouseId: $adjustment->warehouse_id,
                        qty: $qtyOut,
                        referenceType: 'adjustment_out',
                        referenceId: $adjustment->id
                    );

                    $totalLoss += $fifoCost; // Use calculated FIFO cost for loss journal
                }
            }

            // 3. Create General Journal
            if ($totalGain > 0 || $totalLoss > 0) {
                $journal = Journal::create([
                    'journal_number' => 'ADJ-' . uniqid(),
                    'date' => now(),
                    'period_id' => $period->id,
                    'reference_type' => 'inventory_adjustment',
                    'reference_id' => $adjustment->id,
                    'description' => 'Inventory Adjustment ' . $adjustment->number,
                    'status' => 'posted'
                ]);

                if ($totalGain > 0 && $gainAccountId) {
                    // Dr Persediaan, Cr Pendapatan Selisih Stok
                    JournalLine::create([
                        'journal_id' => $journal->id,
                        'account_id' => $setting->inventory_asset_account_id,
                        'debit' => $totalGain,
                        'credit' => 0
                    ]);

                    JournalLine::create([
                        'journal_id' => $journal->id,
                        'account_id' => $gainAccountId,
                        'debit' => 0,
                        'credit' => $totalGain
                    ]);
                }

                if ($totalLoss > 0 && $lossAccountId) {
                    // Dr Beban Selisih Stok, Cr Persediaan
                    JournalLine::create([
                        'journal_id' => $journal->id,
                        'account_id' => $lossAccountId,
                        'debit' => $totalLoss,
                        'credit' => 0
                    ]);

                    JournalLine::create([
                        'journal_id' => $journal->id,
                        'account_id' => $setting->inventory_asset_account_id,
                        'debit' => 0,
                        'credit' => $totalLoss
                    ]);
                }
            }

            $adjustment->status = 'posted';
            $adjustment->save();
        });

        return back()->with('success', 'Adjustment posted successfully.');
    }

    public function void($id)
    {
        $adjustment = InventoryAdjustment::with('items.product')->findOrFail($id);

        if ($adjustment->status != 'posted') {
            return back()->with('error', 'Only posted adjustment can be voided');
        }

        $setting = InventoryAccountSetting::first();

        // Get active period
        $period = AccountingPeriod::where('status', 'open')
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->first();

        if (!$period) {
            $period = AccountingPeriod::where('status', 'open')
                ->where('year', now()->year)
                ->where('month', now()->month)
                ->first();
        }

        if (!$period) {
            return back()->with('error', 'Active accounting period not found for this date. Please open a period first.');
        }

        DB::transaction(function () use ($adjustment, $setting, $period) {

            $totalGain = 0;
            $totalLoss = 0;

            foreach ($adjustment->items as $item) {
                $productId = $item->product_id;
                $warehouseId = $adjustment->warehouse_id;
                $system = $item->system_qty;
                $actual = $item->actual_qty;
                $diff = $actual - $system;

                if ($diff == 0) continue;

                $qty = abs($diff);
                $cost = $item->product->last_cost ?? 0;
                $value = $qty * $cost;

                if ($diff > 0) {
                    $totalGain += $value;
                } else {
                    $totalLoss += $value;
                }

                // USE CENTRALIZED ENGINE
                $engine = app(\App\Core\Inventory\InventoryEngine::class);

                if ($diff > 0) {
                    // Previous was PLUS, void is MINUS
                    $engine->ledger(
                        $productId,
                        $warehouseId,
                        0,
                        $qty,
                        'adjustment_void',
                        $adjustment->number,
                        null,
                        $adjustment->id
                    );
                } else {
                    // Previous was MINUS, void is PLUS
                    $engine->ledger(
                        $productId,
                        $warehouseId,
                        $qty,
                        0,
                        'adjustment_void',
                        $adjustment->number,
                        null,
                        $adjustment->id
                    );
                }

                if ($diff > 0) {
                    // adjustment_in sebelumnya menambah layer, maka void harus mengurangi/menghapus
                    $costLayer = \App\Models\InventoryCostLayer::where('reference_type', 'adjustment_in')
                        ->where('reference_id', $adjustment->id)
                        ->where('product_id', $productId)
                        ->first();

                    if ($costLayer) {
                        $costLayer->qty_balance -= $qty;
                        if ($costLayer->qty_balance <= 0) {
                            $costLayer->delete();
                        } else {
                            $costLayer->save();
                        }
                    }

                    $stockLayer = \App\Core\Inventory\StockLayer::where('source_type', 'adjustment_in')
                        ->where('source_id', $adjustment->id)
                        ->where('product_id', $productId)
                        ->first();

                    if ($stockLayer) {
                        $stockLayer->qty_remaining -= $qty;
                        if ($stockLayer->qty_remaining <= 0) {
                            $stockLayer->delete();
                        } else {
                            $stockLayer->save();
                        }
                    }
                }
            }

            // 3. Create VOID Journal (Reversal)
            if ($totalGain > 0 || $totalLoss > 0) {
                $journal = Journal::create([
                    'journal_number' => 'VOID-ADJ-' . uniqid(),
                    'date' => now(),
                    'period_id' => $period->id,
                    'reference_type' => 'inventory_adjustment_void',
                    'reference_id' => $adjustment->id,
                    'description' => 'Void Inventory Adjustment ' . $adjustment->number,
                    'status' => 'posted'
                ]);

                if ($totalGain > 0) {
                    // Reverse Gain: Dr Gain Account, Cr Inventory Asset
                    JournalLine::create([
                        'journal_id' => $journal->id,
                        'account_id' => $setting->inventory_gain_account_id,
                        'debit' => $totalGain,
                        'credit' => 0
                    ]);

                    JournalLine::create([
                        'journal_id' => $journal->id,
                        'account_id' => $setting->inventory_asset_account_id,
                        'debit' => 0,
                        'credit' => $totalGain
                    ]);
                }

                if ($totalLoss > 0) {
                    // Reverse Loss: Dr Inventory Asset, Cr Loss Account
                    JournalLine::create([
                        'journal_id' => $journal->id,
                        'account_id' => $setting->inventory_asset_account_id,
                        'debit' => $totalLoss,
                        'credit' => 0
                    ]);

                    JournalLine::create([
                        'journal_id' => $journal->id,
                        'account_id' => $setting->inventory_loss_account_id,
                        'debit' => 0,
                        'credit' => $totalLoss
                    ]);
                }
            }

            $adjustment->status = 'void';
            $adjustment->save();
        });

        return back()->with('success', 'Adjustment voided successfully');
    }

    public function destroy($id)
    {
        $adjustment = InventoryAdjustment::findOrFail($id);

        if (in_array($adjustment->status, ['posted', 'void'])) {
            return back()->with('error', 'Only draft adjustments can be deleted');
        }

        $adjustment->items()->delete();
        $adjustment->delete();

        return back()->with('success', 'Adjustment deleted');
    }
}
