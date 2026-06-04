@php
    $status = $invoice->status instanceof \App\Enums\InvoiceStatusEnum 
        ? $invoice->status->value 
        : $invoice->status;
    
    $paidAmount = (float)($invoice->paid_amount ?? 0) + (float)($invoice->advance_applied ?? 0);
    $remaining = round($invoice->grand_total - $paidAmount, 2);
@endphp

<div class="flex justify-between items-center w-full">
    {{-- LEFT: PRIMARY ACTIONS --}}
    <div class="flex gap-2 items-center">
        <a href="{{ route('sales.invoices.index') }}" class="btn btn-secondary btn-sm">
            <i class="fas fa-list mr-1"></i> Daftar
        </a>

        @if($status === 'draft')
            <form action="{{ route('sales.invoices.post', $invoice->id) }}" method="POST" class="inline">
                @csrf
                <button class="btn btn-success btn-sm font-black uppercase tracking-tight">
                    <i class="fas fa-check-circle mr-1"></i> Post
                </button>
            </form>

            <a href="{{ route('sales.invoices.edit', $invoice->id) }}" class="btn btn-warning btn-sm text-white">
                <i class="fas fa-edit mr-1"></i> Edit
            </a>

            <form action="{{ route('sales.invoices.destroy', $invoice->id) }}" method="POST" class="inline" onsubmit="return confirm('Hapus draft invoice ini?')">
                @csrf
                @method('DELETE')
                <button class="btn btn-danger btn-sm">
                    <i class="fas fa-trash mr-1"></i> Hapus
                </button>
            </form>
        @endif

        @if($status === 'posted')
            @if($remaining > 0)
                <a href="{{ route('sales.payment.create', ['customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id]) }}" class="btn btn-primary btn-sm font-black uppercase tracking-tight">
                    <i class="fas fa-money-bill-wave mr-1"></i> Pembayaran
                </a>
                <button type="button" onclick="window._midtransOpen({{ $invoice->id }}, 'link')" class="btn btn-sm bg-emerald-600 hover:bg-emerald-700 text-white font-bold uppercase tracking-tight">
                    <i class="fas fa-link mr-1"></i> Link Bayar
                </button>
                <button type="button" onclick="window._midtransOpen({{ $invoice->id }}, 'qris')" class="btn btn-sm bg-emerald-500 hover:bg-emerald-600 text-white font-bold uppercase tracking-tight">
                    <i class="fas fa-qrcode mr-1"></i> QRIS
                </button>
            @else
                <button class="btn btn-success btn-sm" disabled>
                    <i class="fas fa-check mr-1"></i> LUNAS
                </button>
            @endif

            @if($invoice->canBeVoided())
                <form action="{{ route('sales.invoices.void', $invoice->id) }}" method="POST" class="inline" onsubmit="return confirm('Void invoice ini? Jurnal akan dibatalkan.')">
                    @csrf
                    <button class="btn btn-danger btn-sm opacity-80 hover:opacity-100" style="background-color: #991b1b;">
                        <i class="fas fa-ban mr-1"></i> Void
                    </button>
                </form>
            @endif
        @endif
        
        @if($status === 'void')
             <span class="badge bg-danger rounded-lg px-3 py-2 uppercase font-black tracking-widest">VOIDED</span>
        @endif
    </div>

    {{-- RIGHT: SECONDARY / NEW ACTIONS --}}
    <div class="flex gap-2 items-center">
        @if($status === 'posted' && $remaining > 0)
            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mr-2">
                Sisa: <span class="text-red-500">Rp {{ number_format($remaining, 0, ',', '.') }}</span>
            </span>
        @endif

        @if($status !== 'void')
            <a href="{{ route('sales.invoices.print', $invoice->id) }}" class="btn bg-gray-100 text-gray-600 hover:bg-gray-200 btn-sm">
                <i class="fas fa-print mr-1"></i> Print
            </a>
        @endif

        <a href="{{ route('sales.invoices.create') }}" class="btn btn-outline-primary btn-sm font-bold border-2">
            <i class="fas fa-plus mr-1"></i> Buat Invoice
        </a>
    </div>
</div>
