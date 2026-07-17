<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Models\Supplier;
use App\Core\Inventory\Warehouse;
use App\Core\Inventory\Product;
use App\Core\Accounting\Account;
use App\Modules\FixedAsset\Models\AssetCategory;
use App\Modules\Purchasing\Models\PurchaseOrderExpense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\InlinesPrintAssets;

class PurchaseOrderController extends Controller
{
    use InlinesPrintAssets;

    public function __construct(
        protected PurchaseOrderService $service
    ) {}

    public function print($id)
    {
        $order = PurchaseOrder::with(['supplier', 'warehouse', 'items.product', 'expenses.account'])->findOrFail($id);
        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');
        return view('erp.purchasing.orders.print', compact('order', 'profile'));
    }

    public function downloadPdf($id)
    {
        $order = PurchaseOrder::with(['supplier', 'warehouse', 'items.product', 'expenses.account'])->findOrFail($id);
        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        $html = view('erp.purchasing.orders.print', [
            'order'   => $order,
            'profile' => $profile,
            'pdfMode' => true,
        ])->render();
        $html = $this->inlineLocalAssets($html);

        $bs = \Spatie\Browsershot\Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(0, 0, 0, 0)
            ->emulateMedia('print')
            ->timeout(120)
            ->waitUntilNetworkIdle();

        $nodePath = env('BROWSERSHOT_NODE_PATH', PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\node.exe' : null);
        $npmPath  = env('BROWSERSHOT_NPM_PATH',  PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\npm.cmd'  : null);
        if ($nodePath) $bs->setNodeBinary($nodePath);
        if ($npmPath)  $bs->setNpmBinary($npmPath);

        // Tanpa ini puppeteer cari Chrome di cache default HOME (kosong di server) → 500.
        // Path absolut Chrome di-set via env pool php-fpm (lihat SlipGajiController).
        if ($chromePath = env('BROWSERSHOT_CHROME_PATH')) {
            $bs->setChromePath($chromePath);
        }

        $pdf = $bs->pdf();
        $filename = 'PesananPembelian_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $order->po_number) . '.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function index(Request $request)
    {
        $orders = PurchaseOrder::with('supplier', 'warehouse')
            ->withSum('items as total_qty', 'qty')
            ->withSum('items as invoiced_qty', 'qty_invoiced')
            ->withCount(['invoices as active_invoices_count' => fn($q) => $q->whereIn('status', ['posted', 'draft'])])
            ->addSelect([
                // Total DP yang sudah dibayar untuk PO ini (cocokin via notes "...PO/..." karena supplier_payments tidak punya FK po_id)
                'dp_amount' => DB::table('supplier_payments')
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('supplier_payments.supplier_id', 'purchase_orders.supplier_id')
                    ->where('status', 'posted')
                    ->whereRaw("notes LIKE CONCAT('%', purchase_orders.po_number, '%')")
                    ->whereNotIn('id', function ($q) {
                        $q->select('supplier_payment_id')->from('supplier_payment_allocations');
                    }),
                // Jumlah retur DP posted untuk PO ini
                'po_return_count' => DB::table('purchase_returns')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('purchase_order_id', 'purchase_orders.id')
                    ->where('return_type', 'po')
                    ->where('status', 'posted'),
            ])
            ->when($request->status, function ($q, $s) {
                $poReturnCount = '(SELECT COUNT(*) FROM purchase_returns WHERE purchase_order_id=purchase_orders.id AND return_type=\'po\' AND status=\'posted\')';
                match ($s) {
                    'returned_dp' => $q->whereRaw("$poReturnCount > 0"),
                    default       => $q->where('status', $s),
                };
            })
            ->when($request->supplier_id, fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('po_date', '>=', $d))
            ->when($request->date_to,   fn($q, $d) => $q->whereDate('po_date', '<=', $d))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('po_number', 'like', "%{$term}%")
                      ->orWhere('supplier_invoice_no', 'like', "%{$term}%")
                      ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('po_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $suppliers = Supplier::where('is_active', 1)->orderBy('name')->get();
        return view('erp.purchasing.orders.index', compact('orders', 'suppliers'));
    }

    public function create()
    {
        $suppliers = Supplier::where('is_active', 1)->orderBy('name')->get();
        $warehouses = Warehouse::orderBy('name')->get();
        $products = Product::where('is_active', 1)->orderBy('name')->get();
        $expenseAccounts = Account::whereIn('type', ['expense', 'asset'])
            ->where('is_active', 1)
            ->orderBy('code')
            ->get();
        $assetCategories = AssetCategory::where('is_active', 1)->orderBy('name')->get();
        return view('erp.purchasing.orders.create', compact('suppliers', 'warehouses', 'products', 'expenseAccounts', 'assetCategories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'po_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'supplier_invoice_no' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'ppn_percent' => 'nullable|numeric|min:0|max:100',
            'global_discount_type' => 'nullable|in:nominal,percent',
            'global_discount_value' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.is_asset' => 'nullable|boolean',
            'items.*.asset_category_id' => 'nullable|exists:asset_categories,id',
            'items.*.asset_name' => 'nullable|string|max:200',
            'items.*.description' => 'nullable|string',
            'items.*.qty' => 'required|numeric|gt:0',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount_type' => 'nullable|in:percent,nominal',
            'items.*.discount_value' => 'nullable|numeric|min:0',
            'items.*.unit' => 'nullable|string',
            'expenses' => 'nullable|array',
            'expenses.*.account_id' => 'required_with:expenses|exists:accounts,id',
            'expenses.*.amount' => 'required_with:expenses|numeric|gt:0',
            'expenses.*.mode' => 'required_with:expenses|in:capitalized,direct_expense',
            'expenses.*.description' => 'nullable|string',
        ]);

        try {
            $po = $this->service->createDraft($data);
            if ($request->input('_after_save') === 'print') {
                return redirect()->route('purchasing.orders.show', ['id' => $po->id, 'print' => 1])
                    ->with('success', 'PO dibuat.');
            }
            return redirect(list_url('purchasing.orders.index'))->with('success', 'PO dibuat.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $po = PurchaseOrder::with(['items.product', 'expenses.account', 'supplier', 'warehouse'])->findOrFail($id);
        return view('erp.purchasing.orders.show', compact('po'));
    }

    public function edit($id)
    {
        $po = PurchaseOrder::with(['items.product', 'expenses.account'])->findOrFail($id);
        if (in_array($po->status, ['cancelled', 'closed'])) {
            return redirect()->route('purchasing.orders.show', $po->id)
                ->with('error', 'PO sudah ' . $po->status . ', tidak bisa diedit.');
        }
        if ($po->invoices()->whereIn('status', ['posted', 'draft'])->exists()) {
            return redirect()->route('purchasing.orders.show', $po->id)
                ->with('error', 'PO sudah punya invoice. Tidak bisa diedit.');
        }
        if (\App\Modules\Purchasing\Models\PurchaseReturn::where('purchase_order_id', $po->id)
            ->where('return_type', 'po')
            ->where('status', 'posted')
            ->exists()
        ) {
            return redirect()->route('purchasing.orders.show', $po->id)
                ->with('error', 'PO sudah ada Retur DP. Tidak bisa diedit.');
        }
        $suppliers = Supplier::where('is_active', 1)->orderBy('name')->get();
        $warehouses = Warehouse::orderBy('name')->get();
        $products = Product::where('is_active', 1)->orderBy('name')->get();
        $expenseAccounts = Account::whereIn('type', ['expense', 'asset'])
            ->where('is_active', 1)
            ->orderBy('code')
            ->get();
        $assetCategories = AssetCategory::where('is_active', 1)->orderBy('name')->get();
        return view('erp.purchasing.orders.edit', compact('po', 'suppliers', 'warehouses', 'products', 'expenseAccounts', 'assetCategories'));
    }

    public function update(Request $request, $id)
    {
        $po = PurchaseOrder::findOrFail($id);
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'po_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'supplier_invoice_no' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'ppn_percent' => 'nullable|numeric|min:0|max:100',
            'global_discount_type' => 'nullable|in:nominal,percent',
            'global_discount_value' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.is_asset' => 'nullable|boolean',
            'items.*.asset_category_id' => 'nullable|exists:asset_categories,id',
            'items.*.asset_name' => 'nullable|string|max:200',
            'items.*.description' => 'nullable|string',
            'items.*.qty' => 'required|numeric|gt:0',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount_type' => 'nullable|in:percent,nominal',
            'items.*.discount_value' => 'nullable|numeric|min:0',
            'items.*.unit' => 'nullable|string',
            'expenses' => 'nullable|array',
            'expenses.*.account_id' => 'required_with:expenses|exists:accounts,id',
            'expenses.*.amount' => 'required_with:expenses|numeric|gt:0',
            'expenses.*.mode' => 'required_with:expenses|in:capitalized,direct_expense',
            'expenses.*.description' => 'nullable|string',
        ]);
        try {
            $this->service->update($po, $data);
            if ($request->input('_after_save') === 'print') {
                return redirect()->route('purchasing.orders.show', ['id' => $po->id, 'print' => 1])
                    ->with('success', 'PO diupdate.');
            }
            return redirect(list_url('purchasing.orders.index'))->with('success', 'PO diupdate.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->invoices()->whereIn('status', ['posted', 'draft'])->exists()) {
            return back()->with('error', 'PO sudah punya invoice. Tidak bisa dihapus.');
        }
        $hasDp = DB::table('supplier_payments')
            ->where('supplier_id', $po->supplier_id)
            ->where('status', 'posted')
            ->whereRaw('notes LIKE ?', ['%' . $po->po_number . '%'])
            ->whereNotIn('id', function ($q) {
                $q->select('supplier_payment_id')->from('supplier_payment_allocations');
            })
            ->exists();
        if ($hasDp) {
            return back()->with('error', 'PO sudah punya DP. Tidak bisa dihapus — void DP-nya dulu kalau memang error input.');
        }
        $po->items()->delete();
        $po->expenses()->delete();
        $po->delete();
        return back()->with('success', 'PO dihapus.');
    }

    public function searchExpenseAccounts(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $limit = (int) ($request->get('limit', 15));

        $base = Account::whereIn('type', ['expense', 'asset'])->where('is_active', 1);

        if ($q !== '') {
            $base->where(function ($w) use ($q) {
                $w->where('code', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$q}%");
            });
        }

        $accounts = $base->orderBy('code')->limit($limit)->get(['id', 'code', 'name', 'type']);

        return response()->json($accounts);
    }

    public function frequentExpenseAccounts(Request $request)
    {
        $limit = (int) ($request->get('limit', 6));

        $accountIds = PurchaseOrderExpense::select('account_id', DB::raw('COUNT(*) as usage_count'))
            ->groupBy('account_id')
            ->orderByDesc('usage_count')
            ->limit($limit)
            ->pluck('account_id');

        if ($accountIds->isEmpty()) {
            return response()->json([]);
        }

        $accounts = Account::whereIn('id', $accountIds)
            ->where('is_active', 1)
            ->get(['id', 'code', 'name', 'type'])
            ->keyBy('id');

        $ordered = $accountIds
            ->map(fn($id) => $accounts->get($id))
            ->filter()
            ->values();

        return response()->json($ordered);
    }

    public function poItems($id)
    {
        $po = PurchaseOrder::with('items.product', 'expenses.account')->findOrFail($id);
        return response()->json([
            'po' => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'supplier_id' => $po->supplier_id,
                'warehouse_id' => $po->warehouse_id,
                'ppn_percent' => $po->ppn_percent,
                'global_discount_type' => $po->global_discount_type,
                'global_discount_value' => (float) $po->global_discount_value,
            ],
            'expenses' => $po->expenses->map(fn($e) => [
                'account_id' => $e->account_id,
                'account_code' => $e->account->code ?? '',
                'account_name' => $e->account->name ?? '',
                'description' => $e->description,
                'mode' => $e->mode,
                'amount' => (float) $e->amount,
            ]),
            'items' => $po->items->map(function ($i) {
                return [
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'product_sku' => $i->product->sku ?? '',
                    'product_name' => $i->product->name ?? '',
                    'is_asset' => (bool) $i->is_asset,
                    'asset_category_id' => $i->asset_category_id,
                    'asset_name' => $i->asset_name,
                    'description' => $i->description,
                    'qty' => $i->qty,
                    'qty_invoiced' => $i->qty_invoiced,
                    'qty_remaining' => $i->qty - $i->qty_invoiced,
                    'unit' => $i->unit,
                    'price' => $i->price,
                    'discount' => $i->discount,
                    'discount_type' => $i->discount_type,
                    'discount_value' => $i->discount_value,
                ];
            }),
        ]);
    }
}
