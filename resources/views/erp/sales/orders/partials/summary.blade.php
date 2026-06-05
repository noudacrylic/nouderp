@php
    // Subtotal kini adalah NET (sudah dikurangi diskon per item)
    $subtotal = $so->items->sum('line_total');
    $discountItem = 0; // Tidak ditampilkan di summary agar tidak redundan
    $dpp = $subtotal - $so->global_discount_amount;
    $advancePaid = $advancePaid ?? 0;
    $remaining = $so->grand_total - $advancePaid;
@endphp

@include('erp.components.transaction-summary', [
    'notes' => $so->notes ?? '-',
    'updateNotesUrl' => route('sales.orders.update-notes', $so->id),
    'subtotal' => $subtotal,
    'discountItem' => $discountItem,
    'discountGlobal' => $so->global_discount_amount,
    'dpp' => $dpp,
    'ppn' => $so->ppn_amount,
    'shipping' => $so->shipping_cost,
    'additionalFee' => $so->additional_fee,
    'grandTotal' => $so->grand_total,
    'advancePaid' => $advancePaid,
    'remaining' => ($invoiceStatus === 'invoiced') ? null : $remaining
])

@if(($invoiceStatus ?? '') === 'invoiced')
    <div class="mt-4 p-3 bg-green-50 rounded-lg border border-green-200 text-green-700 text-sm font-bold flex items-center gap-2 shadow-sm">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
        </svg>
        <span>Pesanan Selesai Di-Faktur — Pembayaran dikelola melalui modul Faktur</span>
    </div>
@endif


