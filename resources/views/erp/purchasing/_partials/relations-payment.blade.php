@php
    $payment->loadMissing(['allocations.invoice']);

    $groups = [];

    if ($payment->allocations->isNotEmpty()) {
        $items = [];
        foreach ($payment->allocations as $a) {
            if (!$a->invoice) continue;
            $inv = $a->invoice;
            $sv = strtolower((string) $inv->status);
            $items[] = [
                'number'       => $inv->invoice_number,
                'date'         => optional($inv->invoice_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => false,
                'url'          => route('purchasing.invoices.show', $inv->id),
                'extra'        => 'Rp ' . number_format((float) $a->amount_applied, 0, ',', '.'),
            ];
        }
        if (!empty($items)) {
            $groups[] = ['label' => 'Invoice Dialokasikan', 'items' => $items];
        }
    }

    // Retur PO yang menyerap saldo DP dari payment ini (dp_allocation JSON)
    $dpReturns = \App\Modules\Purchasing\Models\PurchaseReturn::where('return_type', 'po')
        ->whereNotIn('status', ['void', 'cancelled', 'draft'])
        ->whereJsonContains('dp_allocation', [['payment_id' => $payment->id]])
        ->get();
    if ($dpReturns->isEmpty()) {
        // Fallback: whereJsonContains pada nested struct kadang false negative — scan manual.
        $dpReturns = \App\Modules\Purchasing\Models\PurchaseReturn::where('return_type', 'po')
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])
            ->whereNotNull('dp_allocation')
            ->get()
            ->filter(function ($r) use ($payment) {
                foreach ((array) $r->dp_allocation as $alloc) {
                    if ((int) ($alloc['payment_id'] ?? 0) === (int) $payment->id) return true;
                }
                return false;
            });
    }
    if ($dpReturns->isNotEmpty()) {
        $items = [];
        foreach ($dpReturns as $r) {
            $sv = strtolower((string) $r->status);
            $usedFromPayment = 0;
            foreach ((array) $r->dp_allocation as $alloc) {
                if ((int) ($alloc['payment_id'] ?? 0) === (int) $payment->id) {
                    $usedFromPayment += (float) ($alloc['amount'] ?? 0);
                }
            }
            $items[] = [
                'number'       => $r->return_number,
                'date'         => optional($r->return_date)->format('d M Y'),
                'status'       => $sv,
                'status_label' => strtoupper($sv ?: '-'),
                'is_active'    => false,
                'url'          => route('purchasing.returns.show', $r->id),
                'extra'        => 'DP terpakai Rp ' . number_format($usedFromPayment, 0, ',', '.'),
            ];
        }
        $groups[] = ['label' => 'Retur PO (Saldo DP Terpakai)', 'items' => $items];
    }
@endphp

@include('erp.purchasing._partials.relations-shell', [
    'title'   => 'Keterkaitan Dokumen Pembayaran',
    'hint'    => 'Saat payment di-void, alokasi ke invoice & saldo DP yang dipakai retur akan dibalik.',
    'groups'  => $groups,
    'canVoid' => $payment->canBeVoided(),
])
