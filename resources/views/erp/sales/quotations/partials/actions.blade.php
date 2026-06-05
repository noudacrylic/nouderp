@php
    $showText = $showText ?? false;
    $status = $quotation->status;
    
    // Base classes based on context (Index vs Detail)
    $btnClass = $showText 
        ? 'px-3 py-2 text-[11px] font-bold rounded shadow-sm transition-all active:scale-95 uppercase tracking-wider flex items-center gap-1.5' 
        : 'p-1.5 bg-white border border-gray-200 rounded-lg shadow-sm transition-all active:scale-95 hover:bg-gray-50 flex items-center justify-center';
@endphp

<div class="flex flex-wrap items-center gap-1.5" onclick="event.stopPropagation()">

    {{-- ================= DRAFT STATUS ================= --}}
    @if($status === 'draft')
        
        {{-- EDIT BUTTON --}}
        <a href="{{ route('sales.quotations.edit', $quotation->id) }}" title="Edit Quotation"
            class="{{ $btnClass }} bg-amber-500 hover:bg-amber-600 text-white border-none">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            @if($showText) <span>EDIT</span> @endif
        </a>

        {{-- DELETE BUTTON --}}
        <form action="{{ route('sales.quotations.destroy', $quotation->id) }}" method="POST" class="m-0" onsubmit="return confirm('Yakin hapus data ini?')">
            @csrf
            @method('DELETE')
            <button type="submit" title="Hapus Quotation" 
                class="{{ $btnClass }} bg-red-600 hover:bg-red-700 text-white border-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                @if($showText) <span>HAPUS</span> @endif
            </button>
        </form>

        {{-- PRINT BUTTON --}}
        <a href="{{ route('sales.quotations.print', $quotation->id) }}" title="Cetak Quotation"
            class="{{ $btnClass }} bg-gray-700 hover:bg-gray-800 text-white border-none">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            @if($showText) <span>CETAK</span> @endif
        </a>

        {{-- CONVERT TO SO --}}
        <a href="{{ route('sales.orders.create', ['quotation_id' => $quotation->id]) }}" title="Buat Sales Order"
            class="{{ $btnClass }} bg-green-600 hover:bg-green-700 text-white border-none">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 012 2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            @if($showText) <span>BUAT SO</span> @endif
        </a>

    {{-- ================= CONVERTED / OTHER STATUS ================= --}}
    @else
        
        {{-- PRINT BUTTON (Always available) --}}
        <a href="{{ route('sales.quotations.print', $quotation->id) }}" title="Cetak Quotation"
            class="{{ $btnClass }} bg-gray-700 hover:bg-gray-800 text-white border-none">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            @if($showText) <span>CETAK</span> @endif
        </a>

        <div class="{{ $btnClass }} bg-green-100 text-green-700 border-none cursor-default" title="Status: {{ strtoupper($status) }}">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            @if($showText) <span>{{ strtoupper($status) }}</span> @endif
        </div>

    @endif

</div>
