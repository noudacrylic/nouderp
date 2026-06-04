@forelse($invoices as $invoice)
    @php
        $remaining = round($invoice->grand_total - ($invoice->advance_applied ?? 0) - ($invoice->paid_amount ?? 0), 2);
        $isPaid    = $remaining <= 0.01;
    @endphp
    <tr class="hover:bg-blue-50/30 transition-colors cursor-pointer group"
        onclick="window.location='/erp/sales/invoices/{{ $invoice->id }}'">

        <td class="px-6 py-4">
            <span class="text-sm font-bold text-blue-600 group-hover:underline">{{ $invoice->invoice_number }}</span>
        </td>
        <td class="px-6 py-4 text-sm text-gray-600 whitespace-nowrap">
            {{ $invoice->invoice_date->format('d M Y') }}
            @include('erp._partials.age-badge', ['date' => $invoice->invoice_date, 'show' => !$isPaid])
        </td>
        <td class="px-6 py-4">
            <div class="text-sm font-semibold text-gray-800">{{ $invoice->customer->name ?? '-' }}</div>
        </td>
        <td class="px-6 py-4 text-right">
            <span class="text-sm font-bold text-gray-900">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</span>
        </td>

        {{-- STATUS GABUNGAN --}}
        <td class="px-6 py-4 text-center">
            <div class="flex flex-col items-center gap-1">
                {{-- Status utama --}}
                @if($invoice->status === \App\Enums\InvoiceStatusEnum::VOID)
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-red-100 text-red-600 border border-red-200">
                        ✕ Void
                    </span>

                @elseif($invoice->status === \App\Enums\InvoiceStatusEnum::DRAFT)
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-gray-100 text-gray-500 border border-gray-200">
                        ✎ Draft
                    </span>

                @elseif($isPaid)
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-green-100 text-green-700 border border-green-200">
                        ✓ Selesai
                    </span>

                @else
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-amber-100 text-amber-700 border border-amber-200">
                        ⏳ Belum Lunas
                    </span>
                    <span class="text-[9px] text-gray-400 font-medium">
                        Sisa Rp {{ number_format($remaining, 0, ',', '.') }}
                    </span>
                @endif

                {{-- Badge Retur & Garansi --}}
                @if(!empty($invoice->has_return))
                    <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[9px] font-bold bg-orange-100 text-orange-600 border border-orange-200">
                        ↩ Retur
                    </span>
                @endif
                @if(!empty($invoice->has_warranty))
                    <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[9px] font-bold bg-purple-100 text-purple-600 border border-purple-200">
                        🔧 Garansi
                    </span>
                @endif
            </div>
        </td>

        <td class="px-6 py-4 text-center">
            <div class="flex items-center justify-center gap-1.5" onclick="event.stopPropagation();">
                <x-action-button href="{{ url('/erp/sales/invoices/' . $invoice->id . '/print') }}" title="Print Invoice">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                </x-action-button>

                @if(!$isPaid && $invoice->status === \App\Enums\InvoiceStatusEnum::POSTED)
                    <x-action-button href="{{ url('/erp/sales/payment/create?invoice_id=' . $invoice->id) }}" title="Bayar Tagihan">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </x-action-button>
                @endif
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" class="px-6 py-10 text-center text-gray-400 italic">
            No invoices found.
        </td>
    </tr>
@endforelse
