@php
    $delivery->loadMissing(['order', 'invoice']);

    $voidStatuses = ['void', 'cancelled'];
    $statusValue = function ($model) {
        $s = $model->status ?? null;
        if ($s instanceof \BackedEnum) return $s->value;
        return (string) $s;
    };

    $groups = [];

    if ($delivery->order) {
        $so = $delivery->order;
        $sv = $statusValue($so);
        $groups[] = [
            'label' => 'Sales Order',
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

    if ($delivery->invoice) {
        $inv = $delivery->invoice;
        $sv = $statusValue($inv);
        $groups[] = [
            'label' => 'Invoice',
            'items' => [[
                'number'       => $inv->invoice_number,
                'date'         => optional($inv->invoice_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => !in_array($sv, $voidStatuses, true) && $sv !== 'draft',
                'url'          => route('sales.invoices.show', $inv->id),
                'extra'        => 'Rp ' . number_format((float) $inv->grand_total, 0, ',', '.'),
            ]],
        ];
    }

    if (($delivery->reference_type ?? null) === 'warranty_order' && $delivery->reference_id) {
        $war = \App\Models\WarrantyOrder::find($delivery->reference_id);
        if ($war) {
            $sv = strtolower((string) $war->status);
            $groups[] = [
                'label' => 'Garansi',
                'items' => [[
                    'number'       => $war->warranty_number,
                    'date'         => optional($war->warranty_date)->format('d M Y'),
                    'status'       => $sv,
                    'status_label' => strtoupper($sv ?: '-'),
                    'is_active'    => !in_array($sv, $voidStatuses, true) && $sv !== 'draft',
                    'url'          => route('sales.warranty.show', $war->id),
                ]],
            ];
        }
    }
@endphp

@include('erp.sales._partials.relations-shell', [
    'title'   => 'Keterkaitan Dokumen Surat Jalan',
    'hint'    => 'Sebelum void SJ, dokumen di bawah harus sudah tidak aktif.',
    'groups'  => $groups,
    'canVoid' => $delivery->canBeVoided(),
])
