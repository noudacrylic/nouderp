@extends('layouts.erp')

@section('content')
@php
    $stCls = match($ms->status) {
        'draft'  => 'bg-yellow-100 text-yellow-700',
        'posted' => 'bg-green-100 text-green-700',
        'void'   => 'bg-red-100 text-red-700',
    };
    $matched = $ms->lines->where('is_matched', true)->count();
    $unmatched = $ms->lines->count() - $matched;
    $linesLebih = $ms->lines->filter(fn($l) => $l->fee_diff > 0.01)->count();
    $linesKurang = $ms->lines->filter(fn($l) => $l->fee_diff < -0.01)->count();
    $linesSesuai = $ms->lines->count() - $linesLebih - $linesKurang;
    $feePrebooked = $ms->lines->sum('fee_prebooked'); // biaya admin yang sudah tercatat di faktur
@endphp

@if($ms->isDraft())
<div class="bg-amber-50 border border-amber-200 rounded p-3 mb-3 text-sm flex items-start gap-2">
    <span class="text-amber-600">📊</span>
    <div class="flex-1">
        <div class="font-semibold text-amber-900">Hasil Pencocokan</div>
        <div class="text-xs text-amber-800 mt-1 flex gap-3 flex-wrap">
            <span>✓ <b>{{ $linesSesuai }}</b> sesuai (fee aktual = tercatat)</span>
            @if($linesLebih > 0)<span class="text-red-700">⬆ <b>{{ $linesLebih }}</b> fee aktual > tercatat</span>@endif
            @if($linesKurang > 0)<span class="text-blue-700">⬇ <b>{{ $linesKurang }}</b> fee aktual < tercatat</span>@endif
            @if($unmatched > 0)<span class="text-amber-700">⚠ <b>{{ $unmatched }}</b> tidak match faktur</span>@endif
        </div>
        @if(abs($ms->total_fee_diff) > 0.01)
            <div class="text-xs text-amber-800 mt-1">
                Selisih total fee (dibukukan):
                <b class="{{ $ms->total_fee_diff > 0 ? 'text-red-700' : 'text-blue-700' }}">
                    {{ $ms->total_fee_diff > 0 ? '+' : '' }}{{ number_format($ms->total_fee_diff, 0, ',', '.') }}
                </b>
                {{ $ms->total_fee_diff > 0 ? '(marketplace potong lebih banyak dari yg tercatat di faktur)' : '(marketplace potong lebih sedikit dari yg tercatat di faktur)' }}
            </div>
        @endif
        @if($unmatched > 0 && $matched > 0)
            <div class="mt-2 text-xs text-amber-900 bg-white border border-amber-200 rounded px-2 py-1.5">
                💡 Submit akan men-jurnal <b>{{ $matched }} baris matched</b>. Sisa <b>{{ $unmatched }} unmatched</b> dipindah ke settlement baru (DRAF) — akan auto-match saat faktur marketplace baru dibuat, atau klik <b>Retry Match</b>.
            </div>
        @elseif($unmatched > 0 && $matched === 0)
            <div class="mt-2 text-xs text-red-900 bg-white border border-red-200 rounded px-2 py-1.5">
                ⚠ Belum ada line yang match. Tambahkan faktur utk pelanggan ini dulu (sistem akan auto-match), atau klik <b>Retry Match</b>.
            </div>
        @endif
    </div>
</div>
@endif

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Settlement {{ $ms->number }}</h1>
        <div class="text-xs text-gray-500">{{ $ms->marketplaceConfig->customer->name ?? '-' }} · {{ $ms->date->format('d M Y') }}</div>
    </div>
    <div class="flex gap-2 flex-row-reverse">
        @if($ms->isDraft())
            @if($matched > 0)
                @php
                    $submitLabel = $unmatched > 0
                        ? "✓ Submit {$matched} Matched (sisa {$unmatched} ke draf baru)"
                        : "✓ Submit Rekonsiliasi";
                    $confirmMsg = $unmatched > 0
                        ? "Submit {$matched} baris matched? Sisa {$unmatched} unmatched akan dipindah ke settlement baru status DRAF."
                        : "Submit rekonsiliasi {$ms->number}? Jurnal akan dibuat & settlement dipost.";
                @endphp
                <form method="POST" action="{{ route('finance.cash-bank.settlements.post', $ms->id) }}" class="inline" onsubmit="return confirm(@json($confirmMsg))">
                    @csrf
                    <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-1.5 rounded text-sm font-semibold shadow">{{ $submitLabel }}</button>
                </form>
            @endif
            @if($unmatched > 0)
                <form method="POST" action="{{ route('finance.cash-bank.settlements.retry-match', $ms->id) }}" class="inline" onsubmit="return confirm('Re-run matching utk {{ $unmatched }} baris unmatched?')">
                    @csrf
                    <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm font-semibold" title="Re-run matching: cari customer_po_number lagi di Sales Order utk baris yg belum match">🔄 Retry Match</button>
                </form>
                <form method="POST" action="{{ route('finance.cash-bank.settlements.delete-unmatched', $ms->id) }}" class="inline"
                      onsubmit="return confirm(@json("Hapus {$unmatched} baris TIDAK MATCH (fee aktual 0, order belum tercatat di ERP)? Baris ini akan dimasukkan lewat Opening Balance saldo. Tindakan tidak bisa dibatalkan."))">
                    @csrf
                    <button class="border border-red-400 text-red-600 hover:bg-red-50 px-3 py-1.5 rounded text-sm"
                            title="Buang baris tanpa faktur di ERP (rekonsiliasi awal → masuk Opening Balance)">🗑 Hapus {{ $unmatched }} Tidak Match</button>
                </form>
            @endif
            <form method="POST" action="{{ route('finance.cash-bank.settlements.destroy', $ms->id) }}" class="inline" onsubmit="return confirm('Hapus draf?')">
                @csrf @method('DELETE')
                <button class="bg-red-600 text-white px-3 py-1.5 rounded text-sm">Hapus</button>
            </form>
        @elseif($ms->isPosted() && $ms->canBeVoided())
            <form method="POST" action="{{ route('finance.cash-bank.settlements.void', $ms->id) }}" class="inline" onsubmit="return confirm('Void {{ $ms->number }}?')">
                @csrf
                <button class="bg-red-600 text-white px-3 py-1.5 rounded text-sm">Void</button>
            </form>
        @endif
        <a href="{{ route('finance.cash-bank.settlements.index') }}" class="bg-gray-200 px-3 py-1.5 rounded text-sm">← Daftar</a>
    </div>
</div>

<div class="bg-white rounded shadow p-4 mb-3">
    <div class="grid grid-cols-4 gap-3 text-sm">
        <div><div class="text-xs text-gray-500">Status</div><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $ms->status }}</span></div>
        <div><div class="text-xs text-gray-500">Marketplace</div>{{ $ms->marketplaceConfig->customer->name ?? '-' }}</div>
        <div><div class="text-xs text-gray-500">File Sumber</div>{{ $ms->source_filename ?? '-' }}</div>
        <div><div class="text-xs text-gray-500">Jurnal</div>{{ $ms->journal_id ? '#'.$ms->journal_id : '-' }}</div>
        <div><div class="text-xs text-gray-500">Total Gross</div>{{ number_format($ms->total_gross, 0, ',', '.') }}</div>
        <div><div class="text-xs text-gray-500">Fee Tercatat (Faktur)</div>{{ number_format($feePrebooked, 0, ',', '.') }}</div>
        <div><div class="text-xs text-gray-500">Fee Aktual (Marketplace)</div>{{ number_format($ms->total_fee_actual, 0, ',', '.') }}</div>
        <div><div class="text-xs text-gray-500">Selisih (Dibukukan)</div><span class="{{ abs($ms->total_fee_diff) > 0.01 ? 'text-amber-700 font-semibold' : '' }}">{{ number_format($ms->total_fee_diff, 0, ',', '.') }}</span></div>
        <div class="col-span-2"><div class="text-xs text-gray-500">Total Net</div><span class="text-base font-semibold">{{ number_format($ms->total_net, 0, ',', '.') }}</span></div>
        <div><div class="text-xs text-gray-500">Matched</div><span class="text-green-700">{{ $matched }}</span> / {{ $ms->lines->count() }}</div>
        <div><div class="text-xs text-gray-500">Unmatched</div><span class="{{ $unmatched > 0 ? 'text-amber-700' : '' }}">{{ $unmatched }}</span></div>
    </div>
</div>

@php
    $marketplaceName = $ms->marketplaceConfig->customer->name ?? '-';
    $marketplaceOptions = $ms->lines->pluck('order_ref')->isEmpty()
        ? collect([$marketplaceName])
        : collect([$marketplaceName])->unique();
@endphp

{{-- ============ FILTER BAR ============ --}}
<div class="bg-white rounded shadow p-3 mb-2 flex gap-2 items-center flex-wrap">
    <div class="flex-1 min-w-[200px]">
        <input type="text" id="filter_search" placeholder="🔍 Cari Order Ref / Faktur..."
               class="w-full border rounded px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none">
    </div>
    <div>
        <select id="filter_marketplace" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Semua Marketplace</option>
            @foreach($marketplaceOptions as $mp)
                <option value="{{ strtolower($mp) }}">{{ $mp }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <select id="filter_status" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Semua Status</option>
            <option value="sesuai">✓ Sesuai</option>
            <option value="lebih">⬆ Lebih (fee aktual &gt; tercatat)</option>
            <option value="kurang">⬇ Kurang (fee aktual &lt; tercatat)</option>
            <option value="no_match">✗ No Match</option>
        </select>
    </div>
    <button type="button" id="filter_reset" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1.5 rounded text-xs">Reset</button>
    <div class="text-xs text-gray-500 ml-auto">
        Tampil <span id="filter_visible_count" class="font-semibold text-gray-800">{{ $ms->lines->count() }}</span> / {{ $ms->lines->count() }}
    </div>
</div>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600 sticky top-0 z-10">
            <tr>
                <th class="px-3 py-2 text-left">Marketplace</th>
                <th class="px-3 py-2 text-left">Order Ref</th>
                <th class="px-3 py-2 text-left">Tgl Settlement</th>
                <th class="px-3 py-2 text-left">Faktur Match</th>
                <th class="px-3 py-2 text-right">Gross</th>
                <th class="px-3 py-2 text-right">Fee Tercatat</th>
                <th class="px-3 py-2 text-right">Fee Aktual</th>
                <th class="px-3 py-2 text-right">Selisih</th>
                <th class="px-3 py-2 text-right">Net</th>
                <th class="px-3 py-2 text-center">Status</th>
            </tr>
        </thead>
        <tbody id="settlement_lines_body">
            @foreach($ms->lines as $line)
                @php
                    if (!$line->is_matched) {
                        $statusKey = 'no_match';
                    } elseif ($line->fee_diff > 0.01) {
                        $statusKey = 'lebih';
                    } elseif ($line->fee_diff < -0.01) {
                        $statusKey = 'kurang';
                    } else {
                        $statusKey = 'sesuai';
                    }
                    $invoiceNum = $line->is_matched && $line->salesInvoice ? $line->salesInvoice->invoice_number : '';
                    $searchKey = strtolower($line->order_ref . ' ' . $invoiceNum);
                @endphp
                <tr class="settlement-line border-b {{ $line->is_matched ? '' : 'bg-amber-50' }}"
                    data-marketplace="{{ strtolower($marketplaceName) }}"
                    data-status="{{ $statusKey }}"
                    data-search="{{ $searchKey }}">
                    <td class="px-3 py-2 text-xs">{{ $marketplaceName }}</td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $line->order_ref }}</td>
                    <td class="px-3 py-2">{{ $line->settlement_date ? $line->settlement_date->format('d M Y') : '-' }}</td>
                    <td class="px-3 py-2">
                        @if($line->is_matched && $line->salesInvoice)
                            <a href="{{ route('sales.invoices.show', $line->salesInvoice->id) }}"
                               class="text-blue-600 hover:underline font-mono text-xs" target="_blank" rel="noopener">
                                {{ $line->salesInvoice->invoice_number }}
                            </a>
                        @else
                            <span class="text-amber-700 text-xs" title="{{ $line->note }}">⚠ tidak match</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ number_format($line->gross_amount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right text-gray-500">{{ number_format($line->fee_prebooked, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($line->fee_actual, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right {{ abs($line->fee_diff) > 0.01 ? 'text-amber-700 font-semibold' : '' }}">{{ number_format($line->fee_diff, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($line->net_amount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center">
                        @if($statusKey === 'no_match')
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-amber-100 text-amber-700 font-semibold">✗ No Match</span>
                        @elseif($statusKey === 'lebih')
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-red-100 text-red-700 font-semibold" title="Marketplace memotong fee lebih besar dari yang tercatat di faktur">⬆ Lebih</span>
                        @elseif($statusKey === 'kurang')
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-blue-100 text-blue-700 font-semibold" title="Marketplace memotong fee lebih kecil dari yang tercatat di faktur">⬇ Kurang</span>
                        @else
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-green-100 text-green-700 font-semibold">✓ Sesuai</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            <tr id="filter_empty_row" class="hidden">
                <td colspan="10" class="px-3 py-6 text-center text-xs text-gray-400 italic">Tidak ada baris yang cocok dengan filter.</td>
            </tr>
        </tbody>
    </table>
</div>

<script>
(function() {
    const search = document.getElementById('filter_search');
    const mpSel  = document.getElementById('filter_marketplace');
    const stSel  = document.getElementById('filter_status');
    const reset  = document.getElementById('filter_reset');
    const countEl = document.getElementById('filter_visible_count');
    const emptyRow = document.getElementById('filter_empty_row');
    const rows = document.querySelectorAll('tr.settlement-line');

    let searchTimer;

    function applyFilters() {
        const q = search.value.trim().toLowerCase();
        const mp = mpSel.value;
        const st = stSel.value;
        let visible = 0;

        rows.forEach(row => {
            const matchSearch = !q || row.dataset.search.includes(q);
            const matchMp = !mp || row.dataset.marketplace === mp;
            const matchSt = !st || row.dataset.status === st;
            const show = matchSearch && matchMp && matchSt;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        countEl.textContent = visible;
        emptyRow.classList.toggle('hidden', visible > 0);
    }

    search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, 150);
    });
    mpSel.addEventListener('change', applyFilters);
    stSel.addEventListener('change', applyFilters);
    reset.addEventListener('click', () => {
        search.value = '';
        mpSel.value = '';
        stSel.value = '';
        applyFilters();
    });
})();
</script>
@endsection
