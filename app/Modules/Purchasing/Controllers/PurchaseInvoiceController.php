<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Purchasing\Services\PurchaseInvoicePostingService;
use App\Models\Supplier;
use App\Core\Inventory\Warehouse;
use App\Core\Inventory\Product;
use App\Core\Accounting\Account;
use App\Modules\FixedAsset\Models\AssetCategory;
use Illuminate\Http\Request;
use App\Http\Controllers\Concerns\InlinesPrintAssets;

class PurchaseInvoiceController extends Controller
{
    use InlinesPrintAssets;

    public function __construct(
        protected PurchaseInvoiceService $service,
        protected PurchaseInvoicePostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $invoices = PurchaseInvoice::with('supplier', 'warehouse')
            ->selectRaw("purchase_invoices.*, (
                SELECT COUNT(*) FROM purchase_invoices pi2
                WHERE pi2.purchase_order_id = purchase_invoices.purchase_order_id
                  AND pi2.status <> 'void'
            ) as po_sibling_count, (
                SELECT COALESCE(SUM(qty), 0) FROM purchase_invoice_items
                WHERE purchase_invoice_id = purchase_invoices.id
            ) as items_qty_total, (
                SELECT COALESCE(SUM(qty_returned), 0) FROM purchase_invoice_items
                WHERE purchase_invoice_id = purchase_invoices.id
            ) as items_returned_total")
            ->when($request->status, function ($q, $s) {
                $returnedSum = '(SELECT COALESCE(SUM(qty_returned),0) FROM purchase_invoice_items WHERE purchase_invoice_id = purchase_invoices.id)';
                $qtySum      = '(SELECT COALESCE(SUM(qty),0) FROM purchase_invoice_items WHERE purchase_invoice_id = purchase_invoices.id)';
                match ($s) {
                    'draft'            => $q->where('status', 'draft'),
                    'unpaid'           => $q->where('status', 'posted')->where('outstanding_amount', '>', 0),
                    'paid'             => $q->where('status', 'posted')->where('outstanding_amount', '<=', 0),
                    'void'             => $q->where('status', 'void'),
                    'returned_partial' => $q->where('status', 'posted')
                        ->whereRaw("$returnedSum > 0")
                        ->whereRaw("$returnedSum < $qtySum"),
                    'returned_full'    => $q->where('status', 'posted')
                        ->whereRaw("$qtySum > 0")
                        ->whereRaw("$returnedSum >= $qtySum"),
                    default            => null,
                };
            })
            ->when($request->supplier_id, fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($request->date_to,   fn($q, $d) => $q->whereDate('invoice_date', '<=', $d))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('invoice_number', 'like', "%{$term}%")
                      ->orWhere('supplier_invoice_no', 'like', "%{$term}%")
                      ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $suppliers = Supplier::where('is_active', 1)->orderBy('name')->get();
        return view('erp.purchasing.invoices.index', compact('invoices', 'suppliers'));
    }

    public function create(Request $request)
    {
        $suppliers = Supplier::where('is_active', 1)->orderBy('name')->get();
        $warehouses = Warehouse::orderBy('name')->get();
        $products = Product::where('is_active', 1)->orderBy('name')->get();
        $expenseAccounts = Account::whereIn('type', ['expense', 'asset'])
            ->where('is_active', 1)
            ->orderBy('code')
            ->get();
        $po = null;
        if ($request->po_id) {
            $po = PurchaseOrder::with('items.product', 'items.assetCategory', 'expenses.account')->find($request->po_id);
        }
        $assetCategories = AssetCategory::where('is_active', 1)->orderBy('name')->get();
        return view('erp.purchasing.invoices.create', compact('suppliers', 'warehouses', 'products', 'expenseAccounts', 'po', 'assetCategories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'supplier_invoice_no' => 'nullable|string',
            'supplier_invoice_date' => 'nullable|date',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'ppn_percent' => 'nullable|numeric|min:0|max:100',
            'global_discount_type' => 'nullable|in:nominal,percent',
            'global_discount_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id|required_without:items.*.is_asset',
            'items.*.purchase_order_item_id' => 'nullable|exists:purchase_order_items,id',
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

        $action = $request->input('_after_save', '');
        try {
            $invoice = $this->service->createDraft($data);
            if ($action === 'post' || $action === 'print') {
                $this->postingService->post($invoice->refresh());
            }
            if ($action === 'print') {
                return redirect()->route('purchasing.invoices.show', ['id' => $invoice->id, 'print' => 1])
                    ->with('success', 'Invoice dibuat & POSTED.');
            }
            $msg = $action === 'post' ? 'Invoice dibuat & POSTED.' : 'Invoice draft dibuat.';
            return redirect(list_url('purchasing.invoices.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $invoice = PurchaseInvoice::with([
            'items.product',
            'expenses.account',
            'supplier',
            'warehouse',
            'purchaseOrder',
            'returns',
            'paymentAllocations.payment',
        ])->findOrFail($id);
        return view('erp.purchasing.invoices.show', compact('invoice'));
    }

    public function edit($id)
    {
        $invoice = PurchaseInvoice::with(['items.product', 'items.assetCategory', 'expenses.account'])->findOrFail($id);
        if ($invoice->status !== 'draft') {
            return redirect()->route('purchasing.invoices.show', $invoice->id)
                ->with('error', 'Hanya invoice draft yang bisa diedit.');
        }
        $suppliers = Supplier::where('is_active', 1)->orderBy('name')->get();
        $warehouses = Warehouse::orderBy('name')->get();
        $products = Product::where('is_active', 1)->orderBy('name')->get();
        $expenseAccounts = Account::whereIn('type', ['expense', 'asset'])
            ->where('is_active', 1)
            ->orderBy('code')
            ->get();
        $assetCategories = AssetCategory::where('is_active', 1)->orderBy('name')->get();
        return view('erp.purchasing.invoices.edit', compact('invoice', 'suppliers', 'warehouses', 'products', 'expenseAccounts', 'assetCategories'));
    }

    public function update(Request $request, $id)
    {
        $invoice = PurchaseInvoice::findOrFail($id);
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'supplier_invoice_no' => 'nullable|string',
            'supplier_invoice_date' => 'nullable|date',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'ppn_percent' => 'nullable|numeric|min:0|max:100',
            'global_discount_type' => 'nullable|in:nominal,percent',
            'global_discount_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id|required_without:items.*.is_asset',
            'items.*.purchase_order_item_id' => 'nullable|exists:purchase_order_items,id',
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
        $action = $request->input('_after_save', '');
        try {
            $this->service->update($invoice, $data);
            if ($action === 'post' || $action === 'print') {
                $this->postingService->post($invoice->refresh());
            }
            if ($action === 'print') {
                return redirect()->route('purchasing.invoices.show', ['id' => $invoice->id, 'print' => 1])
                    ->with('success', 'Invoice diupdate & POSTED.');
            }
            $msg = $action === 'post' ? 'Invoice diupdate & POSTED.' : 'Invoice diupdate (draft).';
            return redirect(list_url('purchasing.invoices.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function post($id)
    {
        $invoice = PurchaseInvoice::findOrFail($id);
        try {
            $this->postingService->post($invoice);
            return back()->with('success', 'Invoice POSTED. Stok masuk + jurnal terbuat.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $invoice = PurchaseInvoice::findOrFail($id);

        if ($invoice->status !== 'posted') {
            return back()->with('error', 'Hanya invoice posted yang dapat di-void.');
        }

        $activeAlloc = \App\Modules\Purchasing\Models\SupplierPaymentAllocation::where('purchase_invoice_id', $invoice->id)
            ->whereHas('payment', fn($q) => $q->where('status', 'posted'))
            ->with('payment')
            ->first();
        if ($activeAlloc) {
            $payNum = $activeAlloc->payment->payment_number ?? '#' . $activeAlloc->supplier_payment_id;
            return back()->with('error', "Invoice tidak bisa di-void: masih ada Payment {$payNum} aktif. Void payment tersebut terlebih dahulu.");
        }

        $activeReturn = \App\Modules\Purchasing\Models\PurchaseReturn::where('purchase_invoice_id', $invoice->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])
            ->first();
        if ($activeReturn) {
            return back()->with('error', "Invoice tidak bisa di-void: masih ada Retur {$activeReturn->return_number} aktif. Void retur tersebut terlebih dahulu.");
        }

        try {
            $this->postingService->void($invoice);
            return back()->with('success', 'Invoice di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $invoice = PurchaseInvoice::findOrFail($id);
        try {
            $this->service->destroy($invoice);
            return back()->with('success', 'Invoice draft dihapus.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function print($id)
    {
        $invoice = PurchaseInvoice::with(['items.product', 'expenses.account', 'supplier', 'warehouse'])->findOrFail($id);
        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');
        return view('erp.purchasing.invoices.print', compact('invoice', 'profile'));
    }

    public function downloadPdf($id)
    {
        $invoice = PurchaseInvoice::with(['items.product', 'expenses.account', 'supplier', 'warehouse'])->findOrFail($id);
        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        $html = view('erp.purchasing.invoices.print', [
            'invoice' => $invoice,
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

        $pdf = $bs->pdf();
        $filename = 'FakturPembelian_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $invoice->invoice_number) . '.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
