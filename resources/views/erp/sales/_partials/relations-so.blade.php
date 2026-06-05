@php
    $so->loadMissing(['invoices', 'deliveries', 'returns', 'payments.payment', 'billingItems.billing']);

    $voidStatuses = ['void', 'cancelled'];
    $statusValue = function ($model) {
        $s = $model->status ?? null;
        if ($s instanceof \BackedEnum) return $s->value;
        return (string) $s;
    };

    $groups = [];

    // Invoices
    if ($so->invoices->isNotEmpty()) {
        $items = [];
        foreach ($so->invoices as $inv) {
            $sv = $statusValue($inv);
            $items[] = [
                'number'       => $inv->invoice_number,
                'date'         => optional($inv->invoice_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => !in_array($sv, $voidStatuses, true),
                'url'          => route('sales.invoices.show', $inv->id),
                'extra'        => 'Rp ' . number_format((float) $inv->grand_total, 0, ',', '.'),
            ];
        }
        $groups[] = ['label' => 'Faktur', 'items' => $items];
    }

    // Deliveries
    if ($so->deliveries->isNotEmpty()) {
        $items = [];
        foreach ($so->deliveries as $d) {
            $sv = strtolower((string) $d->status);
            $items[] = [
                'number'       => $d->delivery_number,
                'date'         => optional($d->delivery_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => !in_array($sv, $voidStatuses, true),
                'url'          => route('sales.deliveries.show', $d->id),
            ];
        }
        $groups[] = ['label' => 'Surat Jalan', 'items' => $items];
    }

    // Payments (via allocations)
    if ($so->payments->isNotEmpty()) {
        $items = [];
        foreach ($so->payments as $a) {
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
        if (!empty($items)) {
            $groups[] = ['label' => 'Pembayaran / Uang Muka', 'items' => $items];
        }
    }

    // Returns
    if ($so->returns->isNotEmpty()) {
        $items = [];
        foreach ($so->returns as $r) {
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

    // Billing items
    if ($so->billingItems->isNotEmpty()) {
        $items = [];
        foreach ($so->billingItems as $bi) {
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
            ];
        }
        if (!empty($items)) {
            $groups[] = ['label' => 'Billing', 'items' => $items];
        }
    }
@endphp

@include('erp.sales._partials.relations-shell', [
    'title'   => 'Keterkaitan Dokumen Sales Order',
    'hint'    => 'Sebelum void SO, semua dokumen di bawah harus sudah tidak aktif (titik merah = masih memblokir void).',
    'groups'  => $groups,
    'canVoid' => $so->canBeVoided(),
])
