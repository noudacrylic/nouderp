@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Faktur Penjualan</h1>
    <div class="flex gap-2">
        <a href="{{ route('pos.fulfillment.belum-siap') }}" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm" title="Kembali ke Pemrosesan Pesanan (POS)">← Pemrosesan Pesanan</a>
        <a href="{{ route('sales.invoices.excel-import.form') }}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm">Import Excel</a>
        <a href="{{ route('sales.invoices.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Buat Faktur</a>
    </div>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', [
        'name' => 'search',
        'placeholder' => 'Cari nomor faktur atau pelanggan...',
    ])
    @include('erp.purchasing._partials.date-range')
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @php
                $statusOptions = [
                    'draft'            => 'Draf',
                    'belum_lunas'      => 'Belum Lunas',
                    'selesai'          => 'Lunas',
                    'returned_partial' => 'Retur Sebagian',
                    'returned_full'    => 'Retur',
                    'void'             => 'Void',
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
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Faktur</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Pelanggan</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoices as $invoice)
                @php
                    $voidEnum = \App\Enums\InvoiceStatusEnum::VOID ?? null;
                    $draftEnum = \App\Enums\InvoiceStatusEnum::DRAFT ?? null;
                    $postedEnum = \App\Enums\InvoiceStatusEnum::POSTED ?? null;
                    $isVoid = $voidEnum && $invoice->status === $voidEnum;
                    $isDraft = $draftEnum && $invoice->status === $draftEnum;
                    $isPosted = $postedEnum && $invoice->status === $postedEnum;

                    $remaining = round((float)$invoice->grand_total - (float)($invoice->advance_applied ?? 0) - (float)($invoice->paid_amount ?? 0), 2);
                    $isPaid = $remaining <= 0.01;

                    $hasWarranty = !empty($invoice->has_warranty);

                    $qtyTotal = (float) ($invoice->items_qty_total ?? 0);
                    $qtyReturned = (float) ($invoice->items_returned_total ?? 0);
                    $isFullyReturned = $isPosted && $qtyTotal > 0 && $qtyReturned >= $qtyTotal;
                    $isPartiallyReturned = $isPosted && $qtyReturned > 0 && $qtyReturned < $qtyTotal;
                    $isPartial = $invoice->sales_order_id && (int) ($invoice->so_sibling_count ?? 0) > 1;

                    $rowHref = $isDraft
                        ? route('sales.invoices.edit', $invoice->id)
                        : url('/erp/sales/invoices/' . $invoice->id);

                    if ($isVoid) {
                        $stCls = 'bg-red-100 text-red-700';
                        $stLabel = 'Void';
                    } elseif ($isDraft) {
                        $stCls = 'bg-yellow-100 text-yellow-700';
                        $stLabel = 'Draf';
                    } elseif ($isFullyReturned) {
                        $stCls = 'bg-rose-100 text-rose-700';
                        $stLabel = 'Retur';
                    } elseif ($isPaid) {
                        $stCls = 'bg-green-100 text-green-700';
                        $stLabel = 'Lunas';
                    } else {
                        $stCls = 'bg-orange-100 text-orange-700';
                        $stLabel = 'Belum Lunas';
                    }
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $invoice->invoice_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $invoice->invoice_number])
                        @if($isPartial)
                            <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-700 uppercase" title="Faktur sebagian dari SO yang dipecah jadi beberapa faktur">Sebagian</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        {{ $invoice->invoice_date->format('d M Y') }}
                        @include('erp._partials.age-badge', ['date' => $invoice->invoice_date, 'show' => !$isPaid && !$isVoid])
                    </td>
                    <td class="px-3 py-2">{{ $invoice->customer->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $stLabel }}</span>
                        @if(!$isPaid && !$isVoid && !$isDraft && !$isFullyReturned)
                            <div class="text-xs text-red-600 font-semibold mt-0.5">{{ number_format($remaining, 0, ',', '.') }}</div>
                        @endif
                        @if($isPartiallyReturned)
                            <div class="mt-0.5">
                                <span class="px-2 py-0.5 rounded text-xs uppercase bg-rose-100 text-rose-700">Retur Sebagian</span>
                            </div>
                        @endif
                        @if($hasWarranty)
                            <div class="mt-0.5">
                                <span class="px-2 py-0.5 rounded text-xs uppercase bg-purple-100 text-purple-700">Garansi</span>
                            </div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-nowrap">
                            {{-- Print selalu pertama di DOM (paling kanan visual karena flex-row-reverse) --}}
                            @unless($isVoid)
                                <a href="{{ url('/erp/sales/invoices/' . $invoice->id . '/print') }}"
                                   class="bg-gray-700 hover:bg-gray-800 text-white px-2 py-1 rounded text-xs">Cetak</a>
                            @endunless

                            @if($isDraft)
                                <form method="POST" action="{{ route('sales.invoices.post', $invoice->id) }}" class="inline"
                                      onsubmit="return confirm('Post faktur {{ $invoice->invoice_number }}? Stok akan dikurangi & jurnal dicatat.')">
                                    @csrf
                                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                                <form method="POST" action="{{ route('sales.invoices.destroy', $invoice->id) }}" class="inline"
                                      onsubmit="return confirm('Hapus faktur draf {{ $invoice->invoice_number }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>

                            @elseif($isPosted && !$isPaid && !$isFullyReturned)
                                <button type="button"
                                        data-inv-id="{{ $invoice->id }}"
                                        data-catat-url="{{ url('/erp/sales/payment/create?invoice_id=' . $invoice->id) }}"
                                        onclick="window._bayarToggle(event, this)"
                                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-1 rounded text-xs inline-flex items-center gap-1">
                                    Bayar
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                @if($invoice->canBeVoided())
                                    <form method="POST" action="{{ route('sales.invoices.void', $invoice->id) }}" class="inline"
                                          onsubmit="return confirm('Void faktur {{ $invoice->invoice_number }}? Jurnal di-void & stok dikembalikan.')">
                                        @csrf
                                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Void</button>
                                    </form>
                                @endif

                            @elseif(!$isVoid)
                                @if($invoice->canBeVoided())
                                    <form method="POST" action="{{ route('sales.invoices.void', $invoice->id) }}" class="inline"
                                          onsubmit="return confirm('Void faktur {{ $invoice->invoice_number }}? Jurnal di-void.')">
                                        @csrf
                                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Void</button>
                                    </form>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada faktur.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($invoices instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $invoices->links() }}</div>
@endif

{{-- Modal Midtrans (untuk item "Link" pada dropdown Bayar) --}}
@include('erp.sales.payment._midtrans_modals')

{{-- Dropdown Bayar bersama — fixed-position agar tidak terpotong overflow tabel --}}
<div id="bayar_menu" class="hidden fixed z-[9999] w-44 bg-white border border-gray-200 rounded-lg shadow-xl py-1 text-sm">
    <button type="button" id="bayar_menu_link"
            class="w-full text-left px-3 py-2 hover:bg-emerald-50 text-emerald-700 font-semibold flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m6.656-2.828a4 4 0 015.656 0 4 4 0 010 5.656l-1.5 1.5" /></svg>
        Link Pembayaran
    </button>
    <a id="bayar_menu_catat" href="#"
       class="px-3 py-2 hover:bg-indigo-50 text-indigo-700 font-semibold flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        Catat Manual
    </a>
</div>
<script>
(function(){
    const menu = document.getElementById('bayar_menu');
    const linkBtn = document.getElementById('bayar_menu_link');
    const catatLink = document.getElementById('bayar_menu_catat');
    let currentBtn = null, currentInvId = null;

    window._bayarToggle = function(ev, btn){
        ev.stopPropagation();
        if (currentBtn === btn && !menu.classList.contains('hidden')) {
            menu.classList.add('hidden'); currentBtn = null; return;
        }
        currentBtn = btn;
        currentInvId = btn.dataset.invId;
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
        if (currentInvId && typeof window._midtransOpen === 'function') window._midtransOpen(currentInvId, 'link');
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
