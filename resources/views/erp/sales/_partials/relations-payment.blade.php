@php
    $payment->loadMissing(['allocations.invoice', 'allocations.salesOrder', 'allocations.billing']);

    $statusValue = function ($model) {
        $s = $model->status ?? null;
        if ($s instanceof \BackedEnum) return $s->value;
        return (string) $s;
    };

    $groups = [];

    if ($payment->allocations->isNotEmpty()) {
        $invItems = [];
        $soItems = [];
        $bilItems = [];
        foreach ($payment->allocations as $a) {
            if ($a->invoice) {
                $inv = $a->invoice;
                $sv = $statusValue($inv);
                $invItems[] = [
                    'number'       => $inv->invoice_number,
                    'date'         => optional($inv->invoice_date)->format('d M Y'),
                    'status'       => $sv,
                    'status_label' => strtoupper($sv ?: '-'),
                    'is_active'    => false,
                    'url'          => route('sales.invoices.show', $inv->id),
                    'extra'        => 'Rp ' . number_format((float) $a->amount, 0, ',', '.'),
                ];
            }
            if ($a->salesOrder) {
                $so = $a->salesOrder;
                $sv = strtolower((string) $so->status);
                $soItems[] = [
                    'number'       => $so->order_number,
                    'date'         => optional($so->order_date)->format('d M Y'),
                    'status'       => $sv,
                    'status_label' => strtoupper($sv ?: '-'),
                    'is_active'    => false,
                    'url'          => route('sales.orders.show', $so->id),
                    'extra'        => 'Rp ' . number_format((float) $a->amount, 0, ',', '.'),
                ];
            }
            if ($a->billing) {
                $b = $a->billing;
                $sv = $statusValue($b);
                $bilItems[] = [
                    'number'       => $b->billing_number,
                    'date'         => optional($b->date)->format('d M Y'),
                    'status'       => $sv,
                    'status_label' => strtoupper($sv ?: '-'),
                    'is_active'    => false,
                    'url'          => route('sales.billing.show', $b->id),
                    'extra'        => 'Rp ' . number_format((float) $a->amount, 0, ',', '.'),
                ];
            }
        }
        if (!empty($invItems)) $groups[] = ['label' => 'Invoice Dialokasikan', 'items' => $invItems];
        if (!empty($soItems))  $groups[] = ['label' => 'SO Dialokasikan (Uang Muka)', 'items' => $soItems];
        if (!empty($bilItems)) $groups[] = ['label' => 'Billing Dialokasikan', 'items' => $bilItems];
    }
@endphp

@include('erp.sales._partials.relations-shell', [
    'title'   => 'Keterkaitan Dokumen Pembayaran',
    'hint'    => 'Saat payment di-void, alokasi ke dokumen di bawah akan dibalik.',
    'groups'  => $groups,
    'canVoid' => $payment->canBeVoided(),
])
