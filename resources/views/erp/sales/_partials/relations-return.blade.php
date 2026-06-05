@php
    $return->loadMissing(['invoice', 'salesOrder']);

    $statusValue = function ($model) {
        $s = $model->status ?? null;
        if ($s instanceof \BackedEnum) return $s->value;
        return (string) $s;
    };

    $groups = [];

    if ($return->invoice) {
        $inv = $return->invoice;
        $sv = $statusValue($inv);
        $groups[] = [
            'label' => 'Faktur (Sumber)',
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

    if ($return->salesOrder) {
        $so = $return->salesOrder;
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

    // Customer overpayment yang dibuat dari retur ini
    $overpay = \App\Models\CustomerOverpayment::where('reference', $return->return_number)->first();
    if ($overpay) {
        $original = (float) $return->grand_total;
        $remaining = (float) $overpay->amount;
        $alreadyUsed = $remaining + 0.0001 < $original;
        $groups[] = [
            'label' => 'Saldo Pelanggan Overpayment',
            'items' => [[
                'number'       => $overpay->reference ?? '#'.$overpay->id,
                'date'         => optional($overpay->created_at)->format('d M Y'),
                'status'       => $alreadyUsed ? 'used' : 'available',
                'status_label' => $alreadyUsed ? 'SUDAH DIPAKAI' : 'AVAILABLE',
                'is_active'    => $alreadyUsed, // memblokir void retur
                'extra'        => 'Sisa Rp ' . number_format($remaining, 0, ',', '.') . ' / Rp ' . number_format($original, 0, ',', '.'),
            ]],
        ];
    }
@endphp

@include('erp.sales._partials.relations-shell', [
    'title'   => 'Keterkaitan Dokumen Retur',
    'hint'    => 'Saldo overpayment dari retur ini tidak boleh sudah dipakai di Payment lain.',
    'groups'  => $groups,
    'canVoid' => $return->canBeVoided(),
])
