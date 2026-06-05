@php
    $return->loadMissing(['purchaseInvoice', 'purchaseOrder']);

    $groups = [];

    if ($return->purchaseInvoice) {
        $inv = $return->purchaseInvoice;
        $sv = strtolower((string) $inv->status);
        $groups[] = [
            'label' => 'Faktur (Sumber)',
            'items' => [[
                'number'       => $inv->invoice_number,
                'date'         => optional($inv->invoice_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => false,
                'url'          => route('purchasing.invoices.show', $inv->id),
            ]],
        ];
    }

    if ($return->purchaseOrder) {
        $po = $return->purchaseOrder;
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

    if (!empty($return->dp_allocation)) {
        $items = [];
        foreach ((array) $return->dp_allocation as $alloc) {
            $payId = (int) ($alloc['payment_id'] ?? 0);
            $pay = $payId ? \App\Modules\Purchasing\Models\SupplierPayment::find($payId) : null;
            $sv = $pay ? strtolower((string) $pay->status) : '';
            $items[] = [
                'number'       => $pay ? $pay->payment_number : ('Pembayaran #' . $payId),
                'date'         => $pay ? optional($pay->payment_date)->format('d M Y') : null,
                'status'       => $sv ?: 'unknown',
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => false,
                'url'          => $pay ? route('purchasing.payments.show', $pay->id) : null,
                'extra'        => 'Rp ' . number_format((float) ($alloc['amount'] ?? 0), 0, ',', '.'),
            ];
        }
        $groups[] = ['label' => 'Saldo DP yang Dipakai (FIFO)', 'items' => $items];
    }
@endphp

@include('erp.purchasing._partials.relations-shell', [
    'title'   => 'Keterkaitan Dokumen Retur',
    'hint'    => 'Saat retur di-void, qty retur pada invoice & saldo DP yang dipakai akan dikembalikan.',
    'groups'  => $groups,
    'canVoid' => $return->canBeVoided(),
])
