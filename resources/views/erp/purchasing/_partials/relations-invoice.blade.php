@php
    $invoice->loadMissing(['purchaseOrder', 'returns', 'paymentAllocations.payment']);

    $voidStatuses = ['void', 'cancelled'];

    $groups = [];

    if ($invoice->purchaseOrder) {
        $po = $invoice->purchaseOrder;
        $sv = strtolower((string) $po->status);
        $groups[] = [
            'label' => 'Purchase Order (Sumber)',
            'items' => [[
                'number'       => $po->po_number,
                'date'         => optional($po->po_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => false,
                'url'          => route('purchasing.orders.show', $po->id),
            ]],
        ];
    }

    if ($invoice->paymentAllocations->isNotEmpty()) {
        $items = [];
        foreach ($invoice->paymentAllocations as $a) {
            $p = $a->payment;
            if (!$p) continue;
            $sv = strtolower((string) $p->status);
            $items[] = [
                'number'       => $p->payment_number,
                'date'         => optional($p->payment_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => $sv === 'posted',
                'url'          => route('purchasing.payments.show', $p->id),
                'extra'        => 'Rp ' . number_format((float) $a->amount_applied, 0, ',', '.'),
            ];
        }
        if (!empty($items)) {
            $groups[] = ['label' => 'Pembayaran', 'items' => $items];
        }
    }

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
                'url'          => route('purchasing.returns.show', $r->id),
                'extra'        => 'Rp ' . number_format((float) $r->total, 0, ',', '.'),
            ];
        }
        $groups[] = ['label' => 'Retur', 'items' => $items];
    }
@endphp

@include('erp.purchasing._partials.relations-shell', [
    'title'   => 'Keterkaitan Dokumen Invoice',
    'hint'    => 'Sebelum void invoice, semua dokumen di bawah harus sudah tidak aktif (titik merah = masih memblokir void).',
    'groups'  => $groups,
    'canVoid' => $invoice->canBeVoided(),
])
