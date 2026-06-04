@php
    $warranty->loadMissing(['invoice', 'salesOrder', 'delivery']);

    $statusValue = function ($model) {
        $s = $model->status ?? null;
        if ($s instanceof \BackedEnum) return $s->value;
        return (string) $s;
    };

    $groups = [];

    if ($warranty->invoice) {
        $inv = $warranty->invoice;
        $sv = $statusValue($inv);
        $groups[] = [
            'label' => 'Invoice (Sumber)',
            'items' => [[
                'number'       => $inv->invoice_number,
                'date'         => optional($inv->invoice_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => false,
                'url'          => route('sales.invoices.show', $inv->id),
            ]],
        ];
    }

    if ($warranty->salesOrder) {
        $so = $warranty->salesOrder;
        $sv = strtolower((string) $so->status);
        $groups[] = [
            'label' => 'Sales Order (Sumber)',
            'items' => [[
                'number'       => $so->order_number,
                'date'         => optional($so->order_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => false,
                'url'          => route('sales.orders.show', $so->id),
            ]],
        ];
    }

    if ($warranty->delivery) {
        $d = $warranty->delivery;
        $sv = strtolower((string) $d->status);
        $groups[] = [
            'label' => 'Surat Jalan Garansi',
            'items' => [[
                'number'       => $d->delivery_number,
                'date'         => optional($d->delivery_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => $sv === 'posted', // SJ posted = blocking
                'url'          => route('sales.deliveries.show', $d->id),
            ]],
        ];
    }
@endphp

@include('erp.sales._partials.relations-shell', [
    'title'   => 'Keterkaitan Dokumen Garansi',
    'hint'    => 'Sebelum void garansi, SJ Garansi tidak boleh dalam status Posted.',
    'groups'  => $groups,
    'canVoid' => $warranty->canBeVoided(),
])
