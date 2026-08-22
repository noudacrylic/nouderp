<?php

namespace App\Modules\Sales\Services;

use App\Models\SalesInvoice;

class AllocationService
{
    /**
     * Allocate payment amount to invoices using FIFO logic.
     *
     * @param int $customerId
     * @param float $amount
     * @param array $invoiceIds
     * @return array
     */
    public function allocate(int $customerId, float $amount, array $invoiceIds): array
    {
        $invoices = SalesInvoice::where('customer_id', $customerId)
            ->whereIn('id', $invoiceIds)
            // Hanya faktur POSTED yang boleh menyerap pembayaran (status faktur cuma
            // draft|posted|void — saringan '!= paid' dulu meloloskan draft & void).
            ->where('status', \App\Enums\InvoiceStatusEnum::POSTED->value)
            ->orderBy('invoice_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $remaining = $amount;
        $results = [];

        foreach ($invoices as $inv) {
            if ($remaining <= 0) {
                break;
            }

            $invoiceRemaining = round($inv->grand_total - ($inv->advance_applied ?? 0) - $inv->paid_amount, 2);
            
            if ($invoiceRemaining <= 0) {
                continue;
            }

            $alloc = min($remaining, $invoiceRemaining);

            $results[] = [
                'invoice_id' => $inv->id,
                'amount' => $alloc
            ];

            $remaining -= $alloc;
        }

        return $results;
    }
}
