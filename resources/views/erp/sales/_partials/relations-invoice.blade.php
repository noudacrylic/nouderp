@php
    $invoice->loadMissing(['salesOrder', 'delivery', 'returns', 'warrantyOrders', 'billingItems.billing']);

    $activeStatuses = ['posted', 'open', 'partial', 'paid', 'received', 'repaired', 'shipped', 'confirmed'];
    $voidStatuses   = ['void', 'cancelled'];

    $statusValue = function ($model) {
        $s = $model->status ?? null;
        if ($s instanceof \BackedEnum) return $s->value;
        return (string) $s;
    };

    $groups = [];

    // 1. Sales Order (parent)
    if ($invoice->salesOrder) {
        $so = $invoice->salesOrder;
        $sv = $statusValue($so);
        $groups[] = [
            'label' => 'Sales Order (Sumber)',
            'items' => [[
                'number'       => $so->order_number,
                'date'         => optional($so->order_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => false, // SO bukan blocker untuk invoice
                'url'          => route('sales.orders.show', $so->id),
            ]],
        ];
    }

    // 2. Surat Jalan — satu invoice bisa punya BANYAK SJ (partial + auto-SJ sisa, Bagian A).
    $invoiceDeliveries = \App\Modules\Sales\Models\SalesDelivery::where('invoice_id', $invoice->id)
        ->orderBy('id')
        ->get();
    if ($invoiceDeliveries->isNotEmpty()) {
        $items = [];
        foreach ($invoiceDeliveries as $d) {
            $sv = $statusValue($d);
            $items[] = [
                'number'       => $d->delivery_number,
                'date'         => optional($d->delivery_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => false,
                'url'          => route('sales.deliveries.show', $d->id),
            ];
        }
        $groups[] = ['label' => 'Surat Jalan', 'items' => $items];
    }

    // 3. Payment (via allocations)
    $payAlloc = \App\Models\CustomerPaymentAllocation::with('payment')
        ->where('invoice_id', $invoice->id)
        ->get();
    if ($payAlloc->isNotEmpty()) {
        $items = [];
        foreach ($payAlloc as $a) {
            $p = $a->payment;
            if (!$p) continue;
            $sv = strtolower((string) $p->status);
            $items[] = [
                'number'       => $p->payment_number,
                'date'         => optional($p->date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => $sv === 'posted',
                'url'          => route('sales.payment.show', $p->id),
                'extra'        => 'Rp ' . number_format((float) $a->amount, 0, ',', '.'),
            ];
        }
        $groups[] = ['label' => 'Pembayaran', 'items' => $items];
    }

    // 4. Returns
    if ($invoice->returns->isNotEmpty()) {
        $items = [];
        foreach ($invoice->returns as $r) {
            $sv = strtolower((string) $r->status);
            $items[] = [
                'number'       => $r->return_number,
                'date'         => optional($r->return_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => !in_array($sv, $voidStatuses, true) && $sv !== 'draft',
                'url'          => route('sales.returns.show', $r->id),
                'extra'        => 'Rp ' . number_format((float) $r->grand_total, 0, ',', '.'),
            ];
        }
        $groups[] = ['label' => 'Retur', 'items' => $items];
    }

    // 5. Warranty
    if ($invoice->warrantyOrders->isNotEmpty()) {
        $items = [];
        foreach ($invoice->warrantyOrders as $w) {
            $sv = strtolower((string) $w->status);
            $items[] = [
                'number'       => $w->warranty_number,
                'date'         => optional($w->warranty_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => !in_array($sv, $voidStatuses, true) && $sv !== 'draft',
                'url'          => route('sales.warranty.show', $w->id),
            ];
        }
        $groups[] = ['label' => 'Garansi', 'items' => $items];
    }

    // 6. Billing
    if ($invoice->billingItems->isNotEmpty()) {
        $items = [];
        foreach ($invoice->billingItems as $bi) {
            $b = $bi->billing;
            if (!$b) continue;
            $sv = $statusValue($b);
            $items[] = [
                'number'       => $b->billing_number,
                'date'         => optional($b->date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => !in_array($sv, ['void', 'draft'], true),
                'url'          => route('sales.billing.show', $b->id),
                'extra'        => 'Rp ' . number_format((float) $bi->remaining_snapshot, 0, ',', '.'),
            ];
        }
        if (!empty($items)) {
            $groups[] = ['label' => 'Billing', 'items' => $items];
        }
    }
@endphp

@include('erp.sales._partials.relations-shell', [
    'title'   => 'Keterkaitan Dokumen Faktur',
    'hint'    => 'Sebelum void faktur, semua dokumen di bawah harus sudah tidak aktif (titik merah = masih memblokir void).',
    'groups'  => $groups,
    'canVoid' => $invoice->canBeVoided(),
])
