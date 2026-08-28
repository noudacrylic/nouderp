@php
    $showText = $showText ?? false;
    $invStatus = $invoiceStatus ?? ($so->getInvoiceStatus() ?? 'not_invoiced');
    
    // Base classes based on context (Index vs Detail)
    $btnClass = $showText 
        ? 'px-3 py-2 text-[11px] font-bold rounded shadow-sm transition-all active:scale-95 uppercase tracking-wider flex items-center gap-1.5' 
        : 'p-1.5 bg-white border border-gray-200 rounded-lg shadow-sm transition-all active:scale-95 hover:bg-gray-50 flex items-center justify-center';
@endphp

<div class="flex flex-wrap items-center gap-1.5" onclick="event.stopPropagation()">

    {{-- ================= DRAFT STATUS ================= --}}
    @if($so->status === 'draft')
        
        {{-- POST BUTTON --}}
        <form action="{{ route('sales.orders.post', $so->id) }}" method="POST" class="m-0">
            @csrf
            <button type="submit" title="Post Sales Order" 
                class="{{ $btnClass }} bg-green-600 hover:bg-green-700 text-white border-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                @if($showText) <span>POST</span> @endif
            </button>
        </form>

        {{-- EDIT BUTTON --}}
        <a href="{{ route('sales.orders.edit', $so->id) }}" title="Edit Sales Order"
            class="{{ $btnClass }} bg-amber-500 hover:bg-amber-600 text-white border-none">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            @if($showText) <span>EDIT</span> @endif
        </a>

        {{-- DELETE BUTTON --}}
        <form action="{{ route('sales.orders.destroy', $so->id) }}" method="POST" class="m-0" onsubmit="return confirm('Yakin hapus data ini?')">
            @csrf
            @method('DELETE')
            <button type="submit" title="Hapus Sales Order" 
                class="{{ $btnClass }} bg-red-600 hover:bg-red-700 text-white border-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                @if($showText) <span>HAPUS</span> @endif
            </button>
        </form>

    {{-- ================= CONFIRMED STATUS ================= --}}
    @elseif($so->status === 'confirmed')

        {{-- UANG MUKA (via Sales Payment) --}}
        @if(!($so->is_fully_invoiced ?? $so->isFullyInvoiced()))
            <a href="{{ route('sales.payment.create', ['customer_id' => $so->customer_id, 'so_id' => $so->id, 'mode' => 'uang_muka']) }}" title="Tambah Uang Muka via Sales Payment"
                class="{{ $btnClass }} bg-amber-500 hover:bg-amber-600 text-white border-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                @if($showText) <span>UANG MUKA</span> @endif
            </a>

            {{-- LINK PEMBAYARAN (Midtrans) — hanya di halaman detail (modal di-include di sana) --}}
            @if($showText)
                <button type="button" onclick="window._midtransOpenSo({{ $so->id }})" title="Buat tautan pembayaran via Midtrans"
                    class="{{ $btnClass }} bg-emerald-600 hover:bg-emerald-700 text-white border-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m6.656-2.828a4 4 0 015.656 0 4 4 0 010 5.656l-1.5 1.5" />
                    </svg>
                    <span>LINK BAYAR</span>
                </button>
            @endif
        @endif

        {{-- PENGIRIMAN --}}
        <a href="{{ route('sales.deliveries.createFromSO', $so->id) }}" title="Proses Pengiriman"
            class="{{ $btnClass }} bg-purple-600 hover:bg-purple-700 text-white border-none">
            <style>.bg-purple-600 { background-color: #7c3aed; } .hover\:bg-purple-700:hover { background-color: #6d28d9; }</style>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            @if($showText) <span>PENGIRIMAN</span> @endif
        </a>

        {{-- INVOICE --}}
        @if($invStatus !== 'invoiced')
            <a href="{{ route('sales.invoices.create', ['sales_order_id' => $so->id]) }}" title="Buat Faktur"
                class="{{ $btnClass }} bg-blue-600 hover:bg-blue-700 text-white border-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                @if($showText) <span>{{ $invStatus === 'partial' ? 'SISA FAKTUR' : 'BUAT FAKTUR' }}</span> @endif
            </a>
        @else
            <div class="{{ $btnClass }} bg-green-100 text-green-700 border-none cursor-default" title="Faktur Selesai">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                @if($showText) <span>FULL INVOICED</span> @endif
            </div>
        @endif

        {{-- VOID BUTTON (hanya tampil bila tidak ada dokumen turunan aktif) --}}
        @if($so->canBeVoided())
            <form action="{{ route('sales.orders.void', $so->id) }}" method="POST" class="m-0" onsubmit="return confirm('Yakin ingin membatalkan (void) SO ini?')">
                @csrf
                <button type="submit" title="Void Sales Order"
                    class="{{ $btnClass }} bg-gray-600 hover:bg-gray-700 text-white border-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                    @if($showText) <span>VOID</span> @endif
                </button>
            </form>
        @endif

    @endif

    {{-- PRINT BUTTON (Always visible for non-void? Or even for void as history) --}}
    @if($so->status !== 'void')
        <a href="{{ route('sales.orders.print', $so->id) }}" title="Cetak Sales Order"
            class="{{ $btnClass }} bg-gray-500 hover:bg-gray-600 text-white border-none">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            @if($showText) <span>CETAK</span> @endif
        </a>
    @endif

</div>
