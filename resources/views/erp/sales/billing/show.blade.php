@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">
    <!-- BREADCRUMB & ACTIONS -->
    <div class="flex justify-between items-center mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('sales.billing.index') }}" class="text-gray-400 hover:text-gray-600 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-800">Detail Billing: {{ $billing->billing_number }}</h1>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest font-bold">Customer: {{ $billing->customer->name }} • {{ $billing->date->format('d M Y') }}</p>
            </div>
        </div>
        <div class="flex gap-2">
            @if(($billing->total_amount - $billing->paid_amount) > 0)
                <a href="{{ route('sales.payment.create', ['billing_id' => $billing->id]) }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-xs font-bold shadow-sm transition">
                    Proses Pembayaran
                </a>
            @endif

            @if($billing->paid_amount == 0)
                <a href="#" class="bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded text-xs font-bold transition">
                    Edit Billing
                </a>
                <form action="{{ route('sales.billing.destroy', $billing->id) }}" method="POST" onsubmit="return confirm('Hapus billing ini?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="bg-white border border-red-100 text-red-500 hover:bg-red-50 px-4 py-2 rounded text-xs font-bold transition">
                        Hapus
                    </button>
                </form>
            @endif

            @if($billing->canBeVoided())
                <form action="{{ route('sales.billing.void', $billing->id) }}" method="POST" onsubmit="return confirm('Void billing ini?')">
                    @csrf
                    <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded text-xs font-bold transition">
                        Void
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- LEFT: SUMMARY & ITEMS -->
        <div class="lg:col-span-2 space-y-6">
            <!-- HEADER INFO CARD -->
            <div class="bg-white border rounded-lg shadow-sm p-5 grid grid-cols-3 divide-x">
                <div class="px-4 first:pl-0">
                    <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Total Penagihan</label>
                    <span class="text-lg font-black text-gray-900">Rp {{ number_format($billing->total_amount, 0, ',', '.') }}</span>
                </div>
                <div class="px-4">
                    <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1 text-green-600">Terbayar</label>
                    <span class="text-lg font-black text-green-700">Rp {{ number_format($billing->paid_amount ?: 0, 0, ',', '.') }}</span>
                </div>
                <div class="px-4 last:pr-0">
                    <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1 text-orange-600">Sisa Tagihan</label>
                    <span class="text-lg font-black text-orange-700">Rp {{ number_format($billing->total_amount - $billing->paid_amount, 0, ',', '.') }}</span>
                </div>
            </div>

            <!-- ITEMS LIST WITH LIVE SEARCH -->
            <div class="bg-white border rounded-lg shadow-sm overflow-hidden">
                <div class="p-4 border-b bg-gray-50 flex items-center justify-between gap-4">
                    <h2 class="text-sm font-bold text-gray-700">Rincian Dokumen ({{ $billing->items->count() }})</h2>
                    <div class="relative flex-1 max-w-sm">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </span>
                        <input type="text" id="live_search" placeholder="Cari nomor dokumen / tanggal..." class="w-full pl-9 pr-4 py-1.5 text-xs border rounded-lg focus:ring-blue-500 focus:border-blue-500 outline-none transition">
                    </div>
                </div>
                <table class="w-full text-left border-collapse" id="items_table">
                    <thead class="bg-gray-100 border-b">
                        <tr>
                            <th class="px-4 py-2 text-[10px] font-bold text-gray-500 uppercase">Dokumen</th>
                            <th class="px-4 py-2 text-[10px] font-bold text-gray-500 uppercase">Tanggal</th>
                            <th class="px-4 py-2 text-[10px] font-bold text-gray-500 uppercase text-right">Nilai</th>
                            <th class="px-4 py-2 text-[10px] font-bold text-gray-500 uppercase text-right">Sisa Saat Billing</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($billing->items as $item)
                        <tr class="item-row hover:bg-gray-50 transition">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    @if($item->invoice_id)
                                        <a href="{{ route('sales.invoices.show', $item->invoice_id) }}" class="text-sm font-bold text-blue-600 hover:underline search-target" onclick="event.stopPropagation()">
                                            {{ $item->document_number ?? ('INV#'.$item->invoice_id) }}
                                        </a>
                                        <span class="px-1.5 py-0.5 rounded bg-blue-100 text-blue-600 text-[9px] font-bold uppercase">INV</span>
                                    @else
                                        <a href="{{ route('sales.orders.show', $item->sales_order_id) }}" class="text-sm font-bold text-orange-600 hover:underline search-target" onclick="event.stopPropagation()">
                                            {{ $item->document_number ?? ('SO#'.$item->sales_order_id) }}
                                        </a>
                                        <span class="px-1.5 py-0.5 rounded bg-orange-100 text-orange-600 text-[9px] font-bold uppercase">SO</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500 search-target">
                                {{ \Carbon\Carbon::parse($item->document_date)->format('d/m/Y') }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500 font-medium text-right">
                                Rp {{ number_format($item->amount_snapshot, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 text-sm font-black text-gray-900 text-right">
                                Rp {{ number_format($item->remaining_snapshot, 0, ',', '.') }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                <div id="no_results" class="hidden p-10 text-center text-gray-400 bg-gray-50 italic text-sm">
                    Tidak ditemukan dokumen yang cocok dengan kata kunci tersebut.
                </div>
            </div>
        </div>

        <!-- RIGHT: PAYMENT HISTORY -->
        <div class="space-y-6">
            <div class="bg-white border rounded-lg shadow-sm overflow-hidden">
                <div class="p-4 border-b bg-gray-50">
                    <h2 class="text-sm font-bold text-gray-700">Riwayat Pembayaran</h2>
                </div>
                <div class="p-4 space-y-4">
                    @forelse($billing->allocations as $allocation)
                    <div class="flex justify-between items-start border-b pb-3 last:border-0 last:pb-0">
                        <div>
                            <span class="text-xs font-bold text-gray-800 block">{{ $allocation->payment->payment_number }}</span>
                            <span class="text-[10px] text-gray-400 uppercase font-medium">{{ $allocation->payment->date->format('d/m/Y') }}</span>
                        </div>
                        <span class="text-sm font-black text-green-600">Rp {{ number_format($allocation->amount, 0, ',', '.') }}</span>
                    </div>
                    @empty
                    <div class="text-center py-6 text-gray-400 italic text-xs">
                        Belum ada pembayaran untuk billing ini.
                    </div>
                    @endforelse
                </div>
            </div>

            <!-- NOTES SECTION -->
            @if($billing->notes)
            <div class="bg-white border rounded-lg shadow-sm p-4">
                <h2 class="text-[10px] font-bold text-gray-400 uppercase mb-2">Catatan</h2>
                <p class="text-xs text-gray-600 italic">"{{ $billing->notes }}"</p>
            </div>
            @endif
        </div>
    </div>

    @include('erp.sales._partials.relations-billing')
</div>

<script>
document.getElementById('live_search').addEventListener('input', function(e) {
    const keyword = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('.item-row');
    let hasResults = false;

    rows.forEach(row => {
        const text = row.querySelector('.search-target').parentElement.textContent.toLowerCase() + 
                     row.querySelector('.search-target').parentElement.nextElementSibling.textContent.toLowerCase();
        
        if (text.includes(keyword)) {
            row.style.display = '';
            hasResults = true;
        } else {
            row.style.display = 'none';
        }
    });

    const noResults = document.getElementById('no_results');
    const tableHeader = document.querySelector('#items_table thead');
    
    if (hasResults) {
        noResults.classList.add('hidden');
        tableHeader.classList.remove('hidden');
    } else {
        noResults.classList.remove('hidden');
        tableHeader.classList.add('hidden');
    }
});
</script>
@endsection
