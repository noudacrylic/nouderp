<?php

namespace App\Modules\Sales\Services;

use App\Models\CustomerBilling;
use App\Models\CustomerBillingItem;
use App\Models\SalesInvoice;
use App\Enums\InvoiceStatusEnum;
use App\Enums\BillingStatusEnum;
use Illuminate\Support\Facades\DB;

class BillingService
{
    /**
     * Create a new billing grouping multiple invoices.
     *
     * @param array $dto [customer_id, billing_number, date, due_date, invoice_ids]
     * @return CustomerBilling
     * @throws \Exception
     */
    public function create(array $dto): CustomerBilling
    {
        return DB::transaction(function () use ($dto) {
            $billing = CustomerBilling::create([
                'billing_number' => $dto['billing_number'],
                'customer_id' => $dto['customer_id'],
                'date' => $dto['date'],
                'due_date' => $dto['due_date'] ?? null,
                'total_amount' => 0,
                'status' => BillingStatusEnum::OPEN,
            ]);

            $totalAmount = 0;

            foreach ($dto['invoice_ids'] as $invId) {
                $inv = SalesInvoice::lockForUpdate()->find($invId);

                if (!$inv) {
                    throw new \Exception("Invoice ID {$invId} not found");
                }

                if ($inv->customer_id != $dto['customer_id']) {
                    throw new \Exception("Invoice ID {$invId} does not belong to the selected customer");
                }

                if ($inv->status == InvoiceStatusEnum::PAID) {
                    throw new \Exception("Invoice ID {$invId} is already paid");
                }

                $remaining = $inv->grand_total - $inv->paid_amount;

                CustomerBillingItem::create([
                    'customer_billing_id' => $billing->id,
                    'invoice_id' => $invId,
                    'amount_snapshot' => $inv->grand_total,
                    'remaining_snapshot' => $remaining,
                ]);

                $totalAmount += $remaining;
            }

            $billing->update(['total_amount' => $totalAmount]);

            return $billing;
        });
    }

    /**
     * Process payment for one or more billings.
     */
    public function process(array $billingIds, float $amount, ?int $paymentId = null): void
    {
        $remaining = $amount;

        foreach ($billingIds as $billingId) {
            if ($remaining <= 0) break;

            $billing = CustomerBilling::with(['items.invoice', 'items.salesOrder'])->lockForUpdate()->find($billingId);
            if (!$billing) continue;

            $billingRemaining = $billing->total_amount; 
            $paymentForThisBilling = min($remaining, $billingRemaining);

            // Create allocation for the billing itself if paymentId provided
            if ($paymentId) {
                \App\Models\CustomerPaymentAllocation::create([
                    'customer_payment_id' => $paymentId,
                    'billing_id' => $billingId,
                    'amount' => $paymentForThisBilling,
                ]);
            }
            
            // Allocate to underlying items within this billing
            foreach ($billing->items as $item) {
                if ($paymentForThisBilling <= 0) break;

                $targetAmount = 0;
                if ($billing->billing_type === 'invoice' && $item->invoice) {
                    $inv = $item->invoice;
                    $targetAmount = round($inv->grand_total - ($inv->advance_applied ?? 0) - $inv->paid_amount, 2);
                    
                    if ($targetAmount <= 0) continue;
                    $alloc = min($paymentForThisBilling, $targetAmount);
                    
                    $inv->paid_amount += $alloc;
                    $inv->save();

                    if ($paymentId) {
                        \App\Models\CustomerPaymentAllocation::create([
                            'customer_payment_id' => $paymentId,
                            'invoice_id' => $inv->id,
                            'amount' => $alloc,
                        ]);
                    }
                    $paymentForThisBilling -= $alloc;
                    $remaining -= $alloc;
                } 
                elseif ($billing->billing_type === 'sales_order' && $item->salesOrder) {
                    $so = $item->salesOrder;
                    $targetAmount = round($so->grand_total - $so->paid_amount, 2);

                    if ($targetAmount <= 0) continue;
                    $alloc = min($paymentForThisBilling, $targetAmount);

                    $so->paid_amount += $alloc;
                    $so->save();

                    if ($paymentId) {
                        \App\Models\CustomerPaymentAllocation::create([
                            'customer_payment_id' => $paymentId,
                            'sales_order_id' => $so->id,
                            'amount' => $alloc,
                        ]);
                    }
                    $paymentForThisBilling -= $alloc;
                    $remaining -= $alloc;
                }
            }

            // Update billing status
            $this->updateStatus($billing);
        }
    }

    public function updateStatus(CustomerBilling $billing): void
    {
        $billing->load(['items.invoice', 'items.salesOrder']);
        
        // Billing status follows the document lifecycle
        if ($billing->status !== BillingStatusEnum::VOID) {
            // Calculate total paid across all allocations for this billing
            $paid = (float) \App\Models\CustomerPaymentAllocation::where('billing_id', $billing->id)->sum('amount');
            $remaining = round($billing->total_amount - $paid, 2);

            if ($remaining <= 0.01) {
                $billing->status = BillingStatusEnum::PAID;
            } elseif ($paid > 0) {
                $billing->status = BillingStatusEnum::PARTIAL;
            } else {
                $billing->status = BillingStatusEnum::OPEN;
            }
        }

        $billing->save();
    }
}
