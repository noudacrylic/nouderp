@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Sales Order</h1>
    <div class="flex gap-2">
        <a href="{{ route('pos.fulfillment.belum-siap') }}" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm" title="Kembali ke Pemrosesan Pesanan (POS)">← Pemrosesan Pesanan</a>
        <a href="{{ route('sales.orders.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah SO</a>
    </div>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', [
        'name' => 'search',
        'placeholder' => 'Cari No SO atau pelanggan...',
    ])
    @include('erp.purchasing._partials.date-range', [
        'fromName' => 'from',
        'toName'   => 'to',
    ])
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @php
                $statusOptions = [
                    'draft'         => 'Draf',
                    'not_invoiced'  => 'Belum Faktur',
                    'partial'       => 'Sebagian Faktur',
                    'invoiced_full' => 'Full Faktur',
                    'retur'         => 'Retur',
                    'void'          => 'Void',
                ];
            @endphp
            @foreach($statusOptions as $val => $label)
                <option value="{{ $val }}" @selected(request('status')==$val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tipe</label>
        <select name="marketplace" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="non_marketplace" @selected(request('marketplace')=='non_marketplace')>Non-Marketplace</option>
            <option value="marketplace" @selected(request('marketplace')=='marketplace')>Marketplace</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Belum Diproses</label>
        <select name="age" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="lt_1w" @selected(request('age')=='lt_1w')>&lt; 1 minggu</option>
            <option value="1w_1m" @selected(request('age')=='1w_1m')>1 minggu – 1 bulan</option>
            <option value="1m_3m" @selected(request('age')=='1m_3m')>1 bulan – 3 bulan</option>
            <option value="gt_3m" @selected(request('age')=='gt_3m')>&gt; 3 bulan</option>
        </select>
    </div>
    @include('erp._partials.per-page-select')
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No SO</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Pelanggan</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $so)
                @php
                    $isDraft = $so->status === 'draft';
                    $isConfirmed = $so->status === 'confirmed';
                    $isCancelled = $so->status === 'cancelled' || $so->status === 'void';
                    $hasReturn = !empty($so->has_return);

                    $invStatus = method_exists($so, 'getInvoiceStatus') ? $so->getInvoiceStatus() : 'not_invoiced';
                    $isFullyInvoiced = $invStatus === 'invoiced';
                    $isPartialInvoiced = $invStatus === 'partial';

                    // Status pengiriman partial (hanya untuk SO confirmed)
                    $delStatus = ($isConfirmed && !$isCancelled && method_exists($so, 'getDeliveryStatus'))
                        ? $so->getDeliveryStatus() : null;
                    $delBadge = [
                        'not_delivered' => ['BELUM KIRIM', 'bg-red-100 text-red-700'],
                        'partial'       => ['PARTIAL KIRIM', 'bg-amber-100 text-amber-700'],
                        'delivered'     => ['TERKIRIM', 'bg-green-100 text-green-700'],
                    ][$delStatus] ?? null;

                    $rowHref = $isDraft
                        ? route('sales.orders.edit', $so->id)
                        : route('sales.orders.show', $so->id);

                    // Status gabungan — prioritas: Void > Retur > Draft > Full > Partial > Belum Invoice
                    if ($isCancelled) {
                        $stCls = 'bg-gray-200 text-gray-600';
                        $stLabel = 'Void';
                    } elseif ($hasReturn) {
                        $stCls = 'bg-rose-100 text-rose-700';
                        $stLabel = 'Retur';
                    } elseif ($isDraft) {
                        $stCls = 'bg-yellow-100 text-yellow-700';
                        $stLabel = 'Draf';
                    } elseif ($isFullyInvoiced) {
                        $stCls = 'bg-green-100 text-green-700';
                        $stLabel = 'Full Faktur';
                    } elseif ($isPartialInvoiced) {
                        $stCls = 'bg-amber-100 text-amber-700';
                        $stLabel = 'Sebagian Faktur';
                    } else {
                        $stCls = 'bg-gray-100 text-gray-500';
                        $stLabel = 'Belum Faktur';
                    }
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $so->order_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $so->order_number])
                        @if($so->delivery_method === 'ambil_toko')
                            <span class="ml-1 inline-block px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700 align-middle"
                                  title="{{ $so->pickup_code ? 'Booking: '.$so->pickup_code : 'Ambil di Toko' }}">
                                🏬 AMBIL{{ $so->pickup_status === 'picked_up' ? ' ✓' : '' }}
                            </span>
                        @endif
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        {{ \Carbon\Carbon::parse($so->order_date)->format('d M Y') }}
                        @include('erp._partials.age-badge', ['date' => $so->order_date, 'show' => $invStatus === 'not_invoiced' && !$isCancelled])
                    </td>
                    <td class="px-3 py-2">{{ $so->customer->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($so->grand_total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $stLabel }}</span>
                        @if($delBadge)
                            <div class="mt-1">
                                <span class="px-2 py-0.5 rounded text-[10px] uppercase {{ $delBadge[1] }}">{{ $delBadge[0] }}</span>
                            </div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @unless($isCancelled)
                                <a href="{{ route('sales.orders.print', $so->id) }}"
                                   class="bg-gray-700 text-white px-2 py-1 rounded text-xs" title="Cetak SO">Cetak</a>
                            @endunless

                            @if($isDraft)
                                <form method="POST" action="{{ route('sales.orders.post', $so->id) }}" onsubmit="return confirm('POST Sales Order ini?')">
                                    @csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">POST</button>
                                </form>
                                <form method="POST" action="{{ route('sales.orders.destroy', $so->id) }}" onsubmit="return confirm('Hapus SO {{ $so->order_number }}?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif

                            @if($isConfirmed && !$hasReturn)
                                @if($delStatus !== 'delivered')
                                    <a href="{{ route('sales.deliveries.createFromSO', $so->id) }}"
                                       class="bg-orange-600 text-white px-2 py-1 rounded text-xs" title="Buat Pengiriman (boleh sebagian)">Kirim</a>
                                @endif
                                @if($so->deliveries->isNotEmpty())
                                    <a href="{{ route('sales.deliveries.show', $so->deliveries->sortByDesc('id')->first()->id) }}"
                                       class="bg-orange-400 text-white px-2 py-1 rounded text-xs" title="Lihat/Update Resi pengiriman terakhir">Resi</a>
                                @endif
                                @if(!$isFullyInvoiced)
                                    <button type="button"
                                            data-so-id="{{ $so->id }}"
                                            data-catat-url="{{ route('sales.payment.create', ['customer_id' => $so->customer_id, 'so_id' => $so->id, 'mode' => 'uang_muka']) }}"
                                            onclick="window._dpToggle(event, this)"
                                            class="bg-purple-600 text-white px-2 py-1 rounded text-xs inline-flex items-center gap-1" title="Pembayaran DP">
                                        DP
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                    <a href="{{ route('sales.invoices.create', ['sales_order_id' => $so->id]) }}"
                                       class="bg-indigo-600 text-white px-2 py-1 rounded text-xs">+ Faktur</a>
                                @endif
                                @if($so->canBeVoided())
                                    <form method="POST" action="{{ route('sales.orders.void', $so->id) }}" onsubmit="return confirm('Void SO {{ $so->order_number }}?')">
                                        @csrf
                                        <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                    </form>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada Sales Order.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($orders instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $orders->links() }}</div>
@endif

{{-- Modal Midtrans (untuk item "Link" pada dropdown DP) --}}
@include('erp.sales.payment._midtrans_modals')

{{-- Dropdown DP bersama — fixed-position agar tidak terpotong overflow tabel --}}
<div id="dp_menu" class="hidden fixed z-[9999] w-44 bg-white border border-gray-200 rounded-lg shadow-xl py-1 text-sm">
    <button type="button" id="dp_menu_link"
            class="w-full text-left px-3 py-2 hover:bg-emerald-50 text-emerald-700 font-semibold flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m6.656-2.828a4 4 0 015.656 0 4 4 0 010 5.656l-1.5 1.5" /></svg>
        Link Pembayaran
    </button>
    <a id="dp_menu_catat" href="#"
       class="px-3 py-2 hover:bg-purple-50 text-purple-700 font-semibold flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        Catat Manual
    </a>
</div>
<script>
(function(){
    const menu = document.getElementById('dp_menu');
    const linkBtn = document.getElementById('dp_menu_link');
    const catatLink = document.getElementById('dp_menu_catat');
    let currentBtn = null, currentSoId = null;

    window._dpToggle = function(ev, btn){
        ev.stopPropagation();
        if (currentBtn === btn && !menu.classList.contains('hidden')) {
            menu.classList.add('hidden'); currentBtn = null; return;
        }
        currentBtn = btn;
        currentSoId = btn.dataset.soId;
        catatLink.href = btn.dataset.catatUrl;
        menu.classList.remove('hidden');
        const r = btn.getBoundingClientRect();
        let left = r.right - menu.offsetWidth;
        if (left < 8) left = 8;
        let top = r.bottom + 4;
        if (top + menu.offsetHeight > window.innerHeight - 8) top = r.top - menu.offsetHeight - 4;
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    };

    linkBtn.addEventListener('click', function(e){
        e.stopPropagation();
        menu.classList.add('hidden'); currentBtn = null;
        if (currentSoId && typeof window._midtransOpenSo === 'function') window._midtransOpenSo(currentSoId);
    });
    catatLink.addEventListener('click', function(){ menu.classList.add('hidden'); currentBtn = null; });
    document.addEventListener('click', function(){ menu.classList.add('hidden'); currentBtn = null; });
    window.addEventListener('scroll', function(){
        if (!menu.classList.contains('hidden')) { menu.classList.add('hidden'); currentBtn = null; }
    }, true);
})();
</script>

@include('erp.purchasing._partials.list-scripts')
@endsection
