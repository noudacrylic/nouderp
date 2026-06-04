<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Models\CustomerBilling;
use App\Models\CustomerBillingItem;
use App\Models\CustomerPaymentAllocation;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomerBilling::with(['customer', 'items'])
            ->withSum('allocations as paid_amount', 'amount')
            ->latest();

        // 1. Search (Billing # / Customer Name / Item Doc Number)
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('billing_number', 'like', "%{$request->search}%")
                  ->orWhereHas('customer', function ($q2) use ($request) {
                      $q2->where('name', 'like', "%{$request->search}%");
                  })
                  ->orWhereHas('items', function ($q3) use ($request) {
                      $q3->where('document_number', 'like', "%{$request->search}%");
                  });
            });
        }

        // 2. Date Filter
        if ($request->from) {
            $query->whereDate('date', '>=', $request->from);
        }

        if ($request->to) {
            $query->whereDate('date', '<=', $request->to);
        }

        // 3. Billing Type Filter
        if ($request->type) {
            $query->where('billing_type', $request->type);
        }

        $billings = $query->paginate(20)->withQueryString();

        // Status logic
        $billings->getCollection()->transform(function ($b) {
            $paid = (float) ($b->paid_amount ?? 0);
            $total = (float) $b->total_amount;
            $b->remaining = round($total - $paid, 2);

            if ($b->remaining <= 0) {
                $b->payment_status = 'paid';
            } elseif ($paid > 0) {
                $b->payment_status = 'partial';
            } else {
                $b->payment_status = 'unpaid';
            }
            return $b;
        });

        return view('erp.sales.billing.index', compact('billings'));
    }

    public function show($id)
    {
        $billing = CustomerBilling::with(['customer', 'items', 'allocations.payment'])
            ->withSum('allocations as paid_amount', 'amount')
            ->findOrFail($id);

        return view('erp.sales.billing.show', compact('billing'));
    }

    public function create()
    {
        $customers = Customer::where('is_active', true)->get();
        return view('erp.sales.billing.create', compact('customers'));
    }

    public function edit($id)
    {
        $billing = CustomerBilling::with(['customer', 'items'])->findOrFail($id);
        
        // Guard: Jika sudah ada bayar, tidak boleh edit
        $paid = CustomerPaymentAllocation::where('billing_id', $id)->sum('amount');
        if ($paid > 0) {
            return back()->with('error', "Penagihan yang sudah dibayar tidak dapat diedit.");
        }

        $customers = Customer::where('is_active', true)->get();
        return view('erp.sales.billing.edit', compact('billing', 'customers'));
    }

    public function getOpenDocuments($customerId)
    {
        // 1. Invoices
        $invoices = SalesInvoice::where('customer_id', $customerId)
            ->where('status', '!=', 'paid')
            ->whereDoesntHave('billingItems.billing', function($q) {
                $q->whereNotIn('status', ['void', 'paid']);
            })
            ->get()
            ->map(function($inv) {
                $inv->remaining = (float) ($inv->grand_total - $inv->paid_amount - ($inv->advance_applied ?? 0));
                if ($inv->remaining <= 0) return null;
                return $inv;
            })
            ->filter()
            ->values();

        // 2. Sales Orders (for DP)
        $salesOrders = SalesOrder::where('customer_id', $customerId)
            ->where('status', 'confirmed')
            ->whereDoesntHave('invoices')
            ->whereDoesntHave('billingItems.billing', function($q) {
                $q->whereNotIn('status', ['void', 'paid']);
            })
            ->get()
            ->map(function($so) {
                $so->remaining = (float) ($so->grand_total - $so->paid_amount);
                if ($so->remaining <= 0) return null;
                return $so;
            })
            ->filter()
            ->values();

        return response()->json([
            'invoices' => $invoices,
            'sales_orders' => $salesOrders
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'billing_type' => 'required|in:invoice,sales_order',
            'item_ids' => 'required|array',
            'notes' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                // 1. Double Check Type Mix (Backend Guard)
                // Although frontend filters this, we must ensure integrity
                
                $billingType = $request->billing_type;
                $billingNumber = $this->generateBillingNumber();

                $billing = CustomerBilling::create([
                    'billing_number' => $billingNumber,
                    'billing_type' => $billingType,
                    'customer_id' => $request->customer_id,
                    'date' => now(),
                    'due_date' => now()->addDays(7),
                    'total_amount' => 0,
                    'status' => 'open',
                    'notes' => $request->notes,
                ]);

                $totalAmount = 0;
                foreach ($request->item_ids as $id) {
                    if ($request->billing_type === 'invoice') {
                        // Double check lock
                        $exists = CustomerBillingItem::where('invoice_id', $id)
                            ->whereHas('billing', fn($q) => $q->whereNotIn('status', ['void', 'paid']))
                            ->exists();
                        if ($exists) throw new \Exception("Invoice ID $id sudah masuk di penagihan lain yang aktif.");

                        $item = SalesInvoice::findOrFail($id);
                        $remaining = round($item->grand_total - $item->paid_amount - ($item->advance_applied ?? 0), 2);
                        
                        CustomerBillingItem::create([
                            'customer_billing_id' => $billing->id,
                            'invoice_id' => $id,
                            'document_number' => $item->invoice_number,
                            'document_date' => $item->invoice_date,
                            'amount_snapshot' => $item->grand_total,
                            'remaining_snapshot' => $remaining,
                        ]);
                        $totalAmount += $remaining;
                    } else {
                        // Double check lock
                        $exists = CustomerBillingItem::where('sales_order_id', $id)
                            ->whereHas('billing', fn($q) => $q->whereNotIn('status', ['void', 'paid']))
                            ->exists();
                        if ($exists) throw new \Exception("SO ID $id sudah masuk di penagihan lain yang aktif.");

                        $item = SalesOrder::findOrFail($id);
                        $remaining = round($item->grand_total - $item->paid_amount, 2);

                        CustomerBillingItem::create([
                            'customer_billing_id' => $billing->id,
                            'sales_order_id' => $id,
                            'document_number' => $item->order_number,
                            'document_date' => $item->order_date,
                            'amount_snapshot' => $item->grand_total,
                            'remaining_snapshot' => $remaining,
                        ]);
                        $totalAmount += $remaining;
                    }
                }

                $billing->update(['total_amount' => $totalAmount]);

                return redirect(list_url('sales.billing.index'))->with('success', "Billing $billingNumber berhasil disimpan.");
            });
        } catch (\Exception $e) {
            return back()->with('error', "Gagal menyimpan billing: " . $e->getMessage())->withInput();
        }
    }

    public function update(Request $request, $id)
    {
        $billing = CustomerBilling::findOrFail($id);

        $paid = CustomerPaymentAllocation::where('billing_id', $id)->sum('amount');
        if ($paid > 0) {
            return back()->with('error', "Penagihan yang sudah dibayar tidak dapat diubah.");
        }

        $validated = $request->validate([
            'item_ids' => 'required|array',
            'notes' => 'nullable|string',
            'billing_type' => 'required|in:invoice,sales_order',
        ]);

        try {
            return DB::transaction(function () use ($request, $billing) {
                // Update basic info
                $billingType = $request->billing_type;
                
                // Delete old items
                $billing->items()->delete();

                $totalAmount = 0;
                foreach ($request->item_ids as $id) {
                    if ($request->billing_type === 'invoice') {
                        // Double check lock
                        $exists = CustomerBillingItem::where('invoice_id', $id)
                            ->whereHas('billing', fn($q) => $q->whereNotIn('status', ['void', 'paid']))
                            ->where('customer_billing_id', '!=', $billing->id) // Just in case, though items are deleted
                            ->exists();
                        if ($exists) throw new \Exception("Invoice ID $id sudah masuk di penagihan lain yang aktif.");

                        $item = SalesInvoice::findOrFail($id);
                        $remaining = round($item->grand_total - $item->paid_amount - ($item->advance_applied ?? 0), 2);
                        
                        CustomerBillingItem::create([
                            'customer_billing_id' => $billing->id,
                            'invoice_id' => $id,
                            'document_number' => $item->invoice_number,
                            'document_date' => $item->invoice_date,
                            'amount_snapshot' => $item->grand_total,
                            'remaining_snapshot' => $remaining,
                        ]);
                        $totalAmount += $remaining;
                    } else {
                        // Double check lock
                        $exists = CustomerBillingItem::where('sales_order_id', $id)
                            ->whereHas('billing', fn($q) => $q->whereNotIn('status', ['void', 'paid']))
                            ->where('customer_billing_id', '!=', $billing->id) // Just in case
                            ->exists();
                        if ($exists) throw new \Exception("SO ID $id sudah masuk di penagihan lain yang aktif.");

                        $item = SalesOrder::findOrFail($id);
                        $remaining = round($item->grand_total - $item->paid_amount, 2);

                        CustomerBillingItem::create([
                            'customer_billing_id' => $billing->id,
                            'sales_order_id' => $id,
                            'document_number' => $item->order_number,
                            'document_date' => $item->order_date,
                            'amount_snapshot' => $item->grand_total,
                            'remaining_snapshot' => $remaining,
                        ]);
                        $totalAmount += $remaining;
                    }
                }

                $billing->update([
                    'total_amount' => $totalAmount,
                    'notes' => $request->notes,
                    'billing_type' => $request->billing_type,
                ]);

                return redirect(list_url('sales.billing.index'))->with('success', "Billing {$billing->billing_number} berhasil diperbarui.");
            });
        } catch (\Exception $e) {
            return back()->with('error', "Gagal update billing: " . $e->getMessage());
        }
    }

    private function generateBillingNumber()
    {
        $year = now()->year;
        $month = now()->month;

        $sequence = DB::table('document_sequences')
            ->where('document_type', 'billing')
            ->where('year', $year)
            ->where('month', $month)
            ->lockForUpdate()
            ->first();

        if (!$sequence) {
            DB::table('document_sequences')->insert([
                'document_type' => 'billing',
                'year' => $year,
                'month' => $month,
                'last_number' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $number = 1;
        } else {
            $number = $sequence->last_number + 1;
            DB::table('document_sequences')
                ->where('id', $sequence->id)
                ->update([
                    'last_number' => $number,
                    'updated_at' => now()
                ]);
        }

        return sprintf('BLG/%s/%02d/%05d', $year, $month, $number);
    }

    public function destroy($id)
    {
        $billing = CustomerBilling::withSum('allocations as paid_amount', 'amount')->findOrFail($id);

        if ($billing->paid_amount > 0) {
            return back()->with('error', "Gagal dihapus: Billing ini sudah memiliki pembayaran.");
        }

        $billing->delete();
        return back()->with('success', "Billing berhasil dihapus.");
    }

    public function void($id)
    {
        $billing = CustomerBilling::findOrFail($id);

        $statusVal = $billing->status instanceof \App\Enums\BillingStatusEnum
            ? $billing->status->value
            : $billing->status;

        if (in_array($statusVal, ['void', 'draft'], true)) {
            return back()->with('error', 'Billing ini tidak bisa di-void.');
        }

        // ── Dependency check: ada payment alokasi yang belum void ──
        $activeAlloc = CustomerPaymentAllocation::where('billing_id', $billing->id)
            ->whereHas('payment', fn($q) => $q->where('status', 'posted'))
            ->with('payment')
            ->first();
        if ($activeAlloc) {
            $payNum = $activeAlloc->payment->payment_number ?? '#' . $activeAlloc->customer_payment_id;
            return back()->with('error', "Billing tidak bisa di-void: masih ada Payment {$payNum} aktif. Void payment tersebut terlebih dahulu.");
        }

        try {
            DB::transaction(function () use ($billing) {
                $billing->status = \App\Enums\BillingStatusEnum::VOID;
                $billing->save();
            });
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal void billing: ' . $e->getMessage());
        }

        return back()->with('success', 'Billing berhasil di-void.');
    }
}
