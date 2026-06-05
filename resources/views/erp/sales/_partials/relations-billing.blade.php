@php
    $billing->loadMissing(['items', 'allocations.payment']);

    $statusValue = function ($model) {
        $s = $model->status ?? null;
        if ($s instanceof \BackedEnum) return $s->value;
        return (string) $s;
    };

    $groups = [];

    // Dokumen di dalam billing (Invoice / SO)
    if ($billing->items->isNotEmpty()) {
        $invItems = [];
        $soItems = [];
        foreach ($billing->items as $bi) {
            if ($bi->invoice_id) {
                $inv = \App\Models\SalesInvoice::find($bi->invoice_id);
                if ($inv) {
                    $sv = $statusValue($inv);
                    $invItems[] = [
                        'number'       => $inv->invoice_number,
                        'date'         => optional($inv->invoice_date)->format('d M Y'),
                        'status'       => $sv,
                        'status_label' => strtoupper($sv ?: '-'),
                        'is_active'    => false,
                        'url'          => route('sales.invoices.show', $inv->id),
                        'extra'        => 'Rp ' . number_format((float) $bi->remaining_snapshot, 0, ',', '.'),
                    ];
                }
            } elseif ($bi->sales_order_id) {
                $so = \App\Modules\Sales\Models\SalesOrder::find($bi->sales_order_id);
                if ($so) {
                    $sv = strtolower((string) $so->status);
                    $soItems[] = [
                        'number'       => $so->order_number,
                        'date'         => optional($so->order_date)->format('d M Y'),
                        'status'       => $sv,
                        'status_label' => strtoupper($sv ?: '-'),
                        'is_active'    => false,
                        'url'          => route('sales.orders.show', $so->id),
                        'extra'        => 'Rp ' . number_format((float) $bi->remaining_snapshot, 0, ',', '.'),
                    ];
                }
            }
        }
        if (!empty($invItems)) $groups[] = ['label' => 'Faktur (Sumber)', 'items' => $invItems];
        if (!empty($soItems))  $groups[] = ['label' => 'Sales Order (Sumber)', 'items' => $soItems];
    }

    // Payment alokasi
    if ($billing->allocations->isNotEmpty()) {
        $items = [];
        foreach ($billing->allocations as $a) {
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
@endphp

@include('erp.sales._partials.relations-shell', [
    'title'   => 'Keterkaitan Dokumen Billing',
    'hint'    => 'Sebelum void billing, payment yang teralokasi harus sudah di-void.',
    'groups'  => $groups,
    'canVoid' => $billing->canBeVoided(),
])
