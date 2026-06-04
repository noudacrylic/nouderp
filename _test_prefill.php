<?php

$inv = App\Models\SalesInvoice::find(340);
if (!$inv) { echo "Invoice 340 TIDAK ADA\n"; return; }

$statusStr = $inv->status instanceof \App\Enums\InvoiceStatusEnum ? $inv->status->value : (string) $inv->status;
echo "Invoice: {$inv->invoice_number} | status={$statusStr} | customer_id={$inv->customer_id}\n";
$cust = App\Models\Customer::find($inv->customer_id);
echo "Customer: " . ($cust ? $cust->name : 'NULL') . "\n";

$paid = (float) App\Models\CustomerPaymentAllocation::where('invoice_id', $inv->id)->sum('amount');
$remaining = round((float)$inv->grand_total - (float)($inv->advance_applied ?? 0) - $paid, 2);
echo "remaining={$remaining}\n";

$inOpenItems = !in_array($statusStr, ['paid', 'cancelled', 'draft']) && $remaining > 0;
echo "Muncul di open-items? " . ($inOpenItems ? 'YA' : 'TIDAK') . "\n";
