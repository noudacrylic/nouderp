<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Core\Inventory\Product;
use App\Models\InventoryLedger;

class StockLedgerController extends Controller
{
    public function index(Request $request, Product $product)
    {
        // Daftar tahun yang punya transaksi (untuk dropdown filter "Tahun")
        $availableYears = InventoryLedger::where('product_id', $product->id)
            ->selectRaw('YEAR(created_at) as y')
            ->distinct()
            ->orderByDesc('y')
            ->pluck('y')
            ->map(fn ($y) => (string) $y);

        // Tahun & bulan terpilih. Default = tahun + bulan berjalan saat dibuka tanpa filter.
        // Bulan = '01'..'12' atau '' (— Semua Bulan —); Tahun = 'YYYY' atau '' (— Semua —).
        $noFilter = ! $request->hasAny(['year', 'month', 'date_from', 'date_to']);
        $selectedYear  = $noFilter ? now()->format('Y') : (string) $request->input('year', '');
        $selectedMonth = $noFilter ? now()->format('m') : (string) $request->input('month', '');

        // Pastikan tahun terpilih selalu muncul di dropdown (mis. tahun berjalan belum ada transaksi)
        if ($selectedYear !== '' && ! $availableYears->contains($selectedYear)) {
            $availableYears = $availableYears->push($selectedYear)->sortDesc()->values();
        }

        $query = InventoryLedger::where('product_id', $product->id)
            ->with('warehouse');

        if ($selectedYear !== '' || $selectedMonth !== '') {
            // Filter berdasarkan tahun dan/atau bulan — mengabaikan rentang tanggal
            if ($selectedYear !== '') {
                $query->whereYear('created_at', $selectedYear);
            }
            if ($selectedMonth !== '') {
                $query->whereMonth('created_at', $selectedMonth);
            }
        } else {
            if ($request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
        }

        if ($request->warehouse_id) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        $ledgers = $query->orderBy('id', 'asc')->get();

        // Batch-load reference numbers per transaction_type (hindari N+1)
        $refMap = $this->resolveReferences($ledgers);

        return view('erp.inventory.ledger.index', compact('ledgers', 'product', 'refMap', 'availableYears', 'selectedYear', 'selectedMonth'));
    }

    /**
     * Batch-load nomor dokumen & URL untuk setiap entri ledger.
     * Return: ['type' => [id => ['number' => '...', 'url' => '...']]]
     */
    private function resolveReferences($ledgers): array
    {
        $grouped = $ledgers->groupBy('transaction_type');
        $map = [];

        foreach ($grouped as $type => $entries) {
            $ids = $entries->pluck('transaction_id')->filter()->unique()->values()->toArray();
            if (empty($ids)) continue;

            if (in_array($type, ['sale', 'sales_void'])) {
                \App\Modules\Sales\Models\SalesDelivery::whereIn('id', $ids)
                    ->get(['id', 'delivery_number'])
                    ->each(function ($d) use (&$map, $type) {
                        $map[$type][$d->id] = [
                            'number' => $d->delivery_number,
                            'url'    => \Route::has('sales.deliveries.show')
                                        ? route('sales.deliveries.show', $d->id) : null,
                        ];
                    });

            } elseif (in_array($type, ['sales_return', 'sales_return_void'])) {
                \App\Modules\Sales\Models\SalesReturn::whereIn('id', $ids)
                    ->get(['id', 'return_number'])
                    ->each(function ($r) use (&$map, $type) {
                        $map[$type][$r->id] = [
                            'number' => $r->return_number,
                            'url'    => \Route::has('sales.returns.show')
                                        ? route('sales.returns.show', $r->id) : null,
                        ];
                    });

            } elseif (in_array($type, ['production_material', 'production_order'])) {
                \App\Modules\Production\Models\ProductionOrder::whereIn('id', $ids)
                    ->get(['id', 'order_number'])
                    ->each(function ($o) use (&$map, $type) {
                        $map[$type][$o->id] = [
                            'number' => $o->order_number,
                            'url'    => \Route::has('production.orders.show')
                                        ? route('production.orders.show', $o->id) : null,
                        ];
                    });

            } elseif (in_array($type, ['transfer_in', 'transfer_out', 'transfer_void'])) {
                \App\Models\InventoryTransfer::whereIn('id', $ids)
                    ->get(['id', 'number'])
                    ->each(function ($t) use (&$map, $type) {
                        $map[$type][$t->id] = [
                            'number' => $t->number,
                            'url'    => \Route::has('inventory.transfers.show')
                                        ? route('inventory.transfers.show', $t->id) : null,
                        ];
                    });

            } elseif (in_array($type, ['adjustment_in', 'adjustment_out', 'adjustment_void'])) {
                \App\Models\InventoryAdjustment::whereIn('id', $ids)
                    ->get(['id', 'number'])
                    ->each(function ($a) use (&$map, $type) {
                        $map[$type][$a->id] = [
                            'number' => $a->number,
                            // Belum ada halaman show adjustment — arahkan ke halaman edit
                            'url'    => \Route::has('inventory.adjustments.edit')
                                        ? route('inventory.adjustments.edit', $a->id) : null,
                        ];
                    });

            } elseif (in_array($type, ['purchase', 'purchase_void'])) {
                \App\Modules\Purchasing\Models\PurchaseInvoice::whereIn('id', $ids)
                    ->get(['id', 'invoice_number'])
                    ->each(function ($i) use (&$map, $type) {
                        $map[$type][$i->id] = [
                            'number' => $i->invoice_number,
                            'url'    => \Route::has('purchasing.invoices.show')
                                        ? route('purchasing.invoices.show', $i->id) : null,
                        ];
                    });

            } elseif (in_array($type, ['purchase_return', 'purchase_return_void'])) {
                \App\Modules\Purchasing\Models\PurchaseReturn::whereIn('id', $ids)
                    ->get(['id', 'return_number'])
                    ->each(function ($r) use (&$map, $type) {
                        $map[$type][$r->id] = [
                            'number' => $r->return_number,
                            'url'    => \Route::has('purchasing.returns.show')
                                        ? route('purchasing.returns.show', $r->id) : null,
                        ];
                    });

            } elseif ($type === 'opening') {
                foreach ($ids as $id) {
                    $map[$type][$id] = ['number' => 'Saldo Awal', 'url' => null];
                }
            }
        }

        return $map;
    }
}
