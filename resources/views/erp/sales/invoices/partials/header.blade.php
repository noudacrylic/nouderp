<h4>Invoice {{ $invoice->invoice_number }}</h4>

<div class="flex items-center gap-6 text-sm">
    <div>
        <span class="text-gray-500">Status Dokumen:</span>
        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-{{ $invoice->status_class }}-100 text-{{ $invoice->status_class }}-700 border border-{{ $invoice->status_class }}-200">
            {{ $invoice->status_label }}
        </span>
    </div>

    <div>
        <span class="text-gray-500">Status Pembayaran:</span>
        @if($invoice->status === \App\Enums\InvoiceStatusEnum::DRAFT)
            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-gray-100 text-gray-400 border border-gray-200">DRAFT</span>
        @elseif($invoice->remaining_amount <= 0.01)
            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-green-100 text-green-700 border border-green-200">LUNAS</span>
        @elseif($invoice->paid_amount > 0.01)
            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-amber-100 text-amber-700 border border-amber-200">
                SISA: Rp {{ number_format($invoice->remaining_amount, 0, ',', '.') }}
            </span>
        @else
            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-red-100 text-red-700 border border-red-200">BELUM BAYAR</span>
        @endif
    </div>
</div>

<span class="ms-3">Tanggal : {{ $invoice->invoice_date }}</span>
<span class="ms-3">Customer : {{ $invoice->customer->name ?? '-' }}</span>
