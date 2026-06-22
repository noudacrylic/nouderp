<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Customer;
use App\Models\CustomerOverpayment;
use App\Models\SalesInvoice;
use App\Models\CustomerBilling;
use App\Models\CustomerBillingItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Core\Accounting\Account;
use App\Enums\AccountTypeEnum;
use Illuminate\Support\Facades\DB;

use App\Models\CustomerPaymentAllocation;
use App\Http\Controllers\Concerns\InlinesPrintAssets;

class PaymentController extends Controller
{
    use InlinesPrintAssets;

    public function index(Request $request)
    {
        $query = \App\Models\CustomerPayment::with([
            'customer',
            'cashAccount',
            'allocations.invoice',
            'allocations.salesOrder',
            'allocations.billing',
            'salesOrder',
        ]);

        if ($request->search) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('payment_number', 'like', "%{$term}%")
                  ->orWhereHas('customer', fn($c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        if ($request->date_from) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $payments = $query->latest()->paginate(20)->withQueryString();

        return view('erp.sales.payment.index', compact('payments'));
    }

    public function destroy($id)
    {
        $payment = \App\Models\CustomerPayment::findOrFail($id);

        if ($payment->status !== 'draft') {
            return back()->with('error', 'Hanya payment berstatus draft yang dapat dihapus.');
        }

        $payment->allocations()->delete();
        $payment->delete();

        return back()->with('success', 'Payment draft berhasil dihapus.');
    }

    public function post($id, \App\Modules\Sales\Services\CustomerPaymentService $paymentService)
    {
        $payment = \App\Models\CustomerPayment::with('allocations')->findOrFail($id);

        if ($payment->status !== 'draft') {
            return back()->with('error', 'Hanya payment berstatus draft yang dapat dipost.');
        }

        // Infer allocation targets dari draft allocations / payment metadata
        $invoiceIds = $payment->allocations->whereNotNull('invoice_id')->pluck('invoice_id')->toArray();
        $billingId  = $payment->allocations->whereNotNull('billing_id')->pluck('billing_id')->first();
        $soIds      = $payment->allocations->whereNotNull('sales_order_id')->pluck('sales_order_id')->toArray();

        // Fallback: kalau tidak ada allocations, pakai sales_order_id di payment (kasus uang muka)
        if (empty($invoiceIds) && empty($soIds) && !$billingId && $payment->sales_order_id) {
            $soIds = [$payment->sales_order_id];
        }

        if (empty($invoiceIds) && empty($soIds) && !$billingId) {
            return back()->with('error', 'Tidak dapat post: payment belum punya alokasi (invoice/SO/billing). Edit payment terlebih dahulu.');
        }

        try {
            $paymentService->post($payment->id, $billingId, $invoiceIds, $soIds, true);
            return back()->with('success', 'Payment berhasil dipost.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal post: ' . $e->getMessage());
        }
    }

    public function void($id)
    {
        $payment = \App\Models\CustomerPayment::with(['allocations.invoice', 'allocations.salesOrder'])->findOrFail($id);

        if ($payment->status !== 'posted') {
            return back()->with('error', 'Hanya payment berstatus posted yang dapat di-void.');
        }

        try {
            DB::transaction(function () use ($payment) {
                // 1. Rollback paid_amount di invoice & SO
                foreach ($payment->allocations as $alloc) {
                    if ($alloc->invoice) {
                        $inv = $alloc->invoice;
                        $inv->paid_amount = max(0, (float) $inv->paid_amount - (float) $alloc->amount);
                        $inv->save();
                    }
                    if ($alloc->salesOrder) {
                        $so = $alloc->salesOrder;
                        $so->paid_amount = max(0, (float) $so->paid_amount - (float) $alloc->amount);
                        $so->save();
                    }
                }

                // 2. Hapus customer overpayment ledger entries (saldo dipakai + overpay).
                //    GUARD: kelebihan bayar (baris positif) yg dibuat payment ini bisa SUDAH
                //    dipakai transaksi lain (payment/CD berikutnya membuat baris negatif
                //    ber-reference berbeda). Menghapusnya akan membuat saldo customer minus
                //    & menarik kembali kredit yg sudah dibelanjakan → blok void.
                $customerId = $payment->customer_id;
                $currentBalance = (float) \App\Models\CustomerOverpayment::where('customer_id', $customerId)->sum('amount');
                $thisPaymentNet = (float) \App\Models\CustomerOverpayment::where('customer_id', $customerId)
                    ->where('reference', $payment->payment_number)
                    ->sum('amount');
                if (($currentBalance - $thisPaymentNet) < -0.01) {
                    throw new \Exception(
                        'Tidak bisa void: saldo kelebihan bayar yang dihasilkan payment ini sudah '
                        . 'terpakai pada transaksi lain. Void/batalkan transaksi yang memakai saldo '
                        . 'tersebut terlebih dahulu.'
                    );
                }
                \App\Models\CustomerOverpayment::where('reference', $payment->payment_number)->delete();

                // 3. Hapus sales_advances yang dibuat saat post (mirror dari service)
                \App\Modules\Sales\Models\SalesAdvance::where('advance_number', 'ADV-' . $payment->payment_number)->delete();

                // 4. Void jurnal terkait
                \App\Core\Journal\Journal::where('reference_type', 'customer_payment')
                    ->where('reference_id', $payment->id)
                    ->update(['status' => 'void', 'voided_at' => now()]);

                // 5. Set status payment
                $payment->status = 'void';
                $payment->save();
            });

            return back()->with('success', 'Payment berhasil di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal void: ' . $e->getMessage());
        }
    }

    public function print($id)
    {
        $payment = \App\Models\CustomerPayment::with([
            'customer',
            'cashAccount',
            'feeAccount',
            'allocations.invoice',
            'allocations.salesOrder',
            'allocations.billing',
        ])->findOrFail($id);

        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        return view('erp.sales.payment.print', compact('payment', 'profile'));
    }

    public function downloadPdf($id)
    {
        $payment = \App\Models\CustomerPayment::with([
            'customer',
            'cashAccount',
            'feeAccount',
            'allocations.invoice',
            'allocations.salesOrder',
            'allocations.billing',
        ])->findOrFail($id);

        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        $html = view('erp.sales.payment.print', [
            'payment' => $payment,
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
        $filename = 'BuktiPembayaran_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $payment->payment_number) . '.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function show($id)
    {
        $payment = \App\Models\CustomerPayment::with([
            'customer',
            'cashAccount',
            'feeAccount',
            'allocations.invoice',
            'allocations.salesOrder',
            'allocations.billing',
        ])->findOrFail($id);

        return view('erp.sales.payment.show', compact('payment'));
    }

    public function searchCustomers(Request $request)
    {
        $q = $request->get('q');
        return Customer::where('name', 'like', "%$q%")
            ->limit(10)
            ->get(['id', 'name']);
    }

    public function create(Request $request)
    {
        $customers = Customer::all();
        $cashAccounts = Account::where('type', AccountTypeEnum::ASSET)
            ->whereIn('account_category', ['cash', 'cash_equivalent'])
            ->get();

        $prefillCustomer = null;
        if ($request->filled('customer_id')) {
            $prefillCustomer = Customer::find($request->customer_id);
        }

        $prefillInvoiceId = $request->filled('invoice_id') ? (int) $request->invoice_id : null;
        if ($prefillInvoiceId && !$prefillCustomer) {
            $invoice = SalesInvoice::find($prefillInvoiceId);
            if ($invoice) {
                $prefillCustomer = Customer::find($invoice->customer_id);
            }
        }

        $prefillSoId  = $request->filled('so_id') ? (int) $request->so_id : null;
        $prefillMode  = $request->get('mode') === 'uang_muka' ? 'uang_muka' : null;

        return view('erp.sales.payment.create', compact(
            'customers', 'cashAccounts', 'prefillCustomer', 'prefillSoId', 'prefillMode', 'prefillInvoiceId'
        ));
    }

    public function getCustomerSummary($id)
    {
        $balance = CustomerOverpayment::where('customer_id', $id)->sum('amount');
        return response()->json(['balance' => $balance]);
    }

    public function calculateFee(Request $request)
    {
        $cashAccountId = $request->get('cash_account_id');
        $amount = (float)$request->get('amount');

        $setting = \App\Models\PaymentFeeSetting::where('cash_account_id', $cashAccountId)->first();

        if (!$setting) {
            return response()->json(['fee' => 0, 'expense_account_id' => null]);
        }

        $fee = (float)$setting->fee_flat;
        if ($setting->fee_percent > 0) {
            $fee += ($amount * $setting->fee_percent / 100);
        }

        return response()->json([
            'fee' => round($fee, 2),
            'expense_account_id' => $setting->expense_account_id
        ]);
    }

    public function checkFee(Request $request)
    {
        $cashAccountId = $request->get('cash_account_id');
        $setting = \App\Models\PaymentFeeSetting::where('cash_account_id', $cashAccountId)->first();

        if (!$setting) {
            return response()->json(['has_fee' => false]);
        }

        $label = [];
        if ($setting->fee_flat > 0) $label[] = 'Rp ' . number_format($setting->fee_flat);
        if ($setting->fee_percent > 0) $label[] = $setting->fee_percent . '%';

        return response()->json([
            'has_fee' => true,
            'label' => implode(' + ', $label)
        ]);
    }

    public function getOpenItems($id)
    {
        // ══════════════════════════════════════════════
        // 1. INVOICES (Pelunasan mode)
        // ══════════════════════════════════════════════
        $invoices = SalesInvoice::with(['billingItems.billing'])
            ->where('customer_id', $id)
            ->whereNotIn('status', ['paid', 'cancelled', 'draft'])
            ->get()
            ->map(function ($inv) {
                $grandTotal = (float) $inv->grand_total;
                $advance    = (float) ($inv->advance_applied ?? 0);
                $paid       = (float) CustomerPaymentAllocation::where('invoice_id', $inv->id)
                    ->whereHas('payment', fn($q) => $q->where('status', '!=', 'void'))
                    ->sum('amount');
                $remaining  = round($grandTotal - $advance - $paid, 2);

                if ($remaining <= 0) return null;

                // Is this invoice already in an ACTIVE billing?
                $activeBillingItem = $inv->billingItems
                    ->filter(fn($bi) => $bi->billing && !in_array($bi->billing->status, ['void', 'paid']))
                    ->first();

                $inv->remaining  = $remaining;
                $inv->is_locked  = (bool) $activeBillingItem;
                $inv->lock_reason = $activeBillingItem
                    ? "Sudah masuk billing: {$activeBillingItem->billing->billing_number}"
                    : null;
                $inv->date = $inv->invoice_date ? $inv->invoice_date->format('d/m/Y') : null;
                return $inv;
            })
            ->filter()
            ->values();

        // ══════════════════════════════════════════════
        // 2. BILLINGS (both modes – JS will split by billing_type)
        // ══════════════════════════════════════════════
        $billings = CustomerBilling::where('customer_id', $id)
            ->where('status', 'open')
            ->get()
            ->map(function ($billing) {
                $paid      = (float) CustomerPaymentAllocation::where('billing_id', $billing->id)
                    ->whereHas('payment', fn($q) => $q->where('status', '!=', 'void'))
                    ->sum('amount');
                $remaining = round((float) $billing->total_amount - $paid, 2);
                if ($remaining <= 0) return null;

                $billing->remaining  = $remaining;
                $billing->is_locked  = false;
                $billing->lock_reason = null;
                $billing->date       = $billing->date ? \Carbon\Carbon::parse($billing->date)->format('d/m/Y') : null;
                return $billing;
            })
            ->filter()
            ->values();

        // ══════════════════════════════════════════════
        // 3. SALES ORDERS (Uang Muka mode)
        // ══════════════════════════════════════════════
        $salesOrders = SalesOrder::with(['billingItems.billing', 'invoices'])
            ->where('customer_id', $id)
            ->where('status', 'confirmed')
            ->get()
            ->map(function ($so) {
                $grandTotal = (float) $so->grand_total;
                $paid       = (float) CustomerPaymentAllocation::where('sales_order_id', $so->id)
                    ->whereHas('payment', fn($q) => $q->where('status', '!=', 'void'))
                    ->sum('amount');
                $remaining  = round($grandTotal - $paid, 2);

                if ($remaining <= 0) return null;

                $so->remaining = $remaining;

                // Guard 1: Already in active billing (draft, open, partial)
                $activeBillingItem = $so->billingItems
                    ->filter(fn($bi) => $bi->billing && !in_array($bi->billing->status, ['void', 'paid']))
                    ->first();

                if ($activeBillingItem) {
                    $so->is_locked  = true;
                    $so->lock_reason = "Sudah masuk billing: {$activeBillingItem->billing->billing_number}";
                }
                // Guard 2: Already has Invoice
                elseif ($so->invoices->count() > 0) {
                    $so->is_locked  = true;
                    $so->lock_reason = "Sudah menjadi Invoice";
                }
                else {
                    $so->is_locked  = false;
                    $so->lock_reason = null;
                }

                $so->date = $so->order_date ?? null;
                return $so;
            })
            ->filter()
            ->values();

        return response()->json([
            'invoices'     => $invoices,
            'billings'     => $billings,
            'sales_orders' => $salesOrders,
        ]);
    }

    public function store(Request $request, \App\Modules\Sales\Services\CustomerPaymentService $paymentService)
    {
        $validated = $request->validate([
            'customer_id'    => 'required|exists:customers,id',
            'cash_account_id'=> 'required|exists:accounts,id',
            'amount'         => 'required|numeric|min:0.01',
            'admin_fee'      => 'nullable|numeric|min:0',
            'date'           => 'required|date',
            'notes'          => 'nullable|string',
            'item_ids'       => 'required|array',
            'payment_mode'   => 'required|in:pelunasan,uang_muka',
        ]);

        $adminFee = (float)($validated['admin_fee'] ?? 0);
        if ($adminFee > $validated['amount']) {
            return back()->with('error', 'Biaya admin tidak boleh lebih besar dari jumlah pembayaran.')->withInput();
        }

        try {
            DB::beginTransaction();

            $itemDetails = json_decode($request->item_details ?? '[]', true);

            // ── Backend Guard: reject locked documents (double check)
            foreach ($itemDetails as $detail) {
                if ($detail['type'] === 'invoice') {
                    $locked = CustomerBillingItem::where('invoice_id', $detail['id'])
                        ->whereHas('billing', fn($q) => $q->whereNotIn('status', ['void', 'paid']))
                        ->exists();
                    if ($locked) throw new \Exception("Invoice ID {$detail['id']} sudah masuk ke penagihan aktif.");

                } elseif ($detail['type'] === 'sales_order') {
                    $lockedBilling = CustomerBillingItem::where('sales_order_id', $detail['id'])
                        ->whereHas('billing', fn($q) => $q->whereNotIn('status', ['void', 'paid']))
                        ->exists();
                    if ($lockedBilling) throw new \Exception("SO ID {$detail['id']} sudah masuk ke penagihan aktif.");

                    $hasInvoice = SalesInvoice::where('sales_order_id', $detail['id'])->exists();
                    if ($hasInvoice) throw new \Exception("SO ID {$detail['id']} sudah menjadi Invoice. Gunakan pelunasan invoice.");
                }
            }

            $isAdvance = $validated['payment_mode'] === 'uang_muka';
            $firstSoId = null;
            if ($isAdvance) {
                $firstSoId = collect($itemDetails)->where('type', 'sales_order')->pluck('id')->first();
                if (!$firstSoId) {
                    $firstSoId = collect($validated['item_ids'])->first();
                }
            }

            $payment = $paymentService->create([
                'customer_id'    => $validated['customer_id'],
                'cash_account_id'=> $validated['cash_account_id'],
                'amount'         => $validated['amount'],
                'admin_fee'      => $adminFee,
                'date'           => $validated['date'],
                'notes'          => $validated['notes'],
                'payment_type'   => $isAdvance ? 'advance' : 'invoice',
                'sales_order_id' => $firstSoId,
            ]);

            // Split items by type
            $invoiceIds   = collect($itemDetails)->where('type', 'invoice')->pluck('id')->toArray();
            $billingId    = collect($itemDetails)->where('type', 'billing')->pluck('id')->first();
            $soIds        = collect($itemDetails)->where('type', 'sales_order')->pluck('id')->toArray();

            if (empty($invoiceIds) && !$billingId && empty($soIds)) {
                // Fallback: use item_ids directly (legacy support)
                if ($request->payment_mode === 'pelunasan') {
                    $invoiceIds = $validated['item_ids'];
                } else {
                    $soIds = $validated['item_ids'];
                }
            }

            $paymentService->post($payment->id, $billingId, $invoiceIds, $soIds, true);

            DB::commit();
            return redirect(list_url('sales.payment.index'))->with('success', 'Pembayaran berhasil disimpan.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage())->withInput();
        }
    }
}
