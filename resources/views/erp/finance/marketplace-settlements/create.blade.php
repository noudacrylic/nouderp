@extends('layouts.erp')

@section('content')
@php
    // Group pending invoices by customer (marketplace) untuk display
    $pendingGrouped = $pendingInvoices->groupBy('customer_id');
    $totalPending = $pendingInvoices->count();
    $totalPendingAmount = $pendingInvoices->sum('grand_total');
@endphp

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Upload Settlement Marketplace</h1>
        <p class="text-xs text-gray-500">Cocokkan dana cair marketplace dengan faktur yang belum di-settle.</p>
    </div>
    <a href="{{ route('finance.cash-bank.settlements.index') }}" class="bg-gray-200 px-3 py-1.5 rounded text-sm">← Daftar</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    {{-- ============ KIRI: PENDING TRANSAKSI ============ --}}
    <div class="bg-white rounded shadow p-4">
        <div class="flex items-center justify-between mb-3 pb-3 border-b">
            <div>
                <div class="font-semibold text-sm">📋 Faktur Marketplace Belum Settled</div>
                <div class="text-[11px] text-gray-500 mt-0.5">
                    {{ $totalPending }} faktur · Rp {{ number_format($totalPendingAmount, 0, ',', '.') }}
                </div>
            </div>
            @if($pendingInvoices->isNotEmpty())
                <input type="text" id="pending_search" placeholder="Cari order/pelanggan..."
                       class="border rounded px-2 py-1 text-xs w-40">
            @endif
        </div>

        @if($pendingInvoices->isEmpty())
            <div class="text-center py-12 text-xs text-gray-400 italic">
                Tidak ada faktur marketplace yang menunggu settlement.
            </div>
        @else
            <div class="max-h-[600px] overflow-y-auto pr-1 space-y-3" id="pending_list">
                @foreach($pendingGrouped as $customerId => $invoices)
                    @php $cust = $invoices->first()->customer; @endphp
                    <div class="pending-group">
                        <div class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5 pending-group-header">
                            🏬 {{ $cust->name ?? '#'.$customerId }} <span class="text-gray-400 font-normal">({{ $invoices->count() }})</span>
                        </div>
                        <div class="space-y-1.5">
                            @foreach($invoices as $inv)
                                @php $po = $inv->salesOrder->customer_po_number ?? null; @endphp
                                <div class="pending-item border border-gray-200 rounded px-3 py-2 text-xs flex justify-between items-center hover:bg-blue-50"
                                     data-search="{{ strtolower($inv->invoice_number . ' ' . ($po ?? '') . ' ' . ($cust->name ?? '')) }}">
                                    <div class="min-w-0 flex-1">
                                        <div class="font-mono font-semibold text-gray-800 truncate">{{ $inv->invoice_number }}</div>
                                        <div class="text-[10px] text-gray-400 mt-0.5">
                                            {{ $inv->invoice_date->format('d M Y') }}
                                            @if($po && $po !== $inv->invoice_number)
                                                · PO: <span class="font-mono">{{ $po }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="text-right ml-3 shrink-0">
                                        <div class="font-semibold text-gray-900">Rp {{ number_format($inv->grand_total, 0, ',', '.') }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div id="pending_empty" class="hidden text-center py-8 text-xs text-gray-400 italic">
                Tidak ada hasil cocok.
            </div>
        @endif
    </div>

    {{-- ============ KANAN: UPLOAD + PROMPT ============ --}}
    <div class="space-y-4">

        {{-- AI Prompt --}}
        <div class="bg-white rounded shadow p-4">
            <div class="flex items-center justify-between mb-2">
                <div class="font-semibold text-sm">🤖 Prompt AI (Universal)</div>
                <button type="button" id="btn_copy_prompt"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 rounded text-xs font-semibold">
                    📋 Copy Prompt
                </button>
            </div>
            <div class="text-[11px] text-gray-500 mb-2">
                Copy → paste ke <a href="https://claude.ai" target="_blank" class="text-blue-600 underline">Claude.ai</a> / ChatGPT bareng raw Excel marketplace. Boleh banyak file (Shopee + TikTok + Lazada + dll) sekaligus → AI keluarkan 1 file gabungan.
            </div>
            <textarea id="universal_prompt" readonly rows="8"
                      class="w-full border rounded px-2 py-1.5 text-[11px] font-mono bg-gray-50 resize-y">{{ $universalPrompt }}</textarea>
            <div id="copy_success" class="hidden mt-1.5 text-[11px] text-green-700 font-semibold">✓ Prompt sudah di-copy ke clipboard.</div>
        </div>

        {{-- Upload form --}}
        <div class="bg-white rounded shadow p-4">
            <div class="font-semibold text-sm mb-3 pb-2 border-b">📤 Upload File Format Baku</div>

            <form method="POST" action="{{ route('finance.cash-bank.settlements.store') }}" enctype="multipart/form-data">
                @csrf

                @if(session('error') || $errors->any())
                    <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-xs mb-3">
                        {{ session('error') }}
                        @foreach ($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
                    </div>
                @endif

                <div class="bg-blue-50 border border-blue-100 text-blue-800 px-3 py-2 rounded text-[11px] mb-3">
                    Format Excel hasil AI (4 kolom):
                    <code class="block bg-white rounded px-2 py-1 text-[10px] font-mono mt-1">marketplace | order_ref | settlement_date | net_amount</code>
                </div>

                <div class="space-y-3 text-sm">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Tanggal Settlement <span class="text-red-500">*</span></label>
                        <input type="date" name="date" value="{{ old('date', $prefillDate ?: now()->toDateString()) }}"
                               class="border rounded px-2 py-1.5 w-full text-sm" required>
                        <div class="text-[10px] text-gray-400 mt-0.5">Tanggal dokumen settlement (untuk jurnal).</div>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">File Excel Format Baku <span class="text-red-500">*</span></label>
                        <input type="file" name="file" accept=".xlsx,.xls,.csv"
                               class="border rounded px-2 py-1.5 w-full text-sm" required>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Catatan</label>
                        <textarea name="notes" rows="2" class="border rounded px-2 py-1.5 w-full text-sm">{{ old('notes') }}</textarea>
                    </div>
                </div>

                <div class="flex justify-end border-t pt-3 mt-4">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-semibold">
                        Upload & Lihat Pencocokan →
                    </button>
                </div>
            </form>

            <div class="mt-3 pt-3 border-t text-[11px] text-gray-500">
                💡 Setelah upload, sistem akan tampilkan hasil pencocokan (match / selisih lebih / kurang) untuk konfirmasi sebelum di-submit.
            </div>
        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── Copy prompt ──
    const btnCopy = document.getElementById('btn_copy_prompt');
    const promptEl = document.getElementById('universal_prompt');
    const successEl = document.getElementById('copy_success');

    if (btnCopy) {
        btnCopy.addEventListener('click', async function() {
            try {
                await navigator.clipboard.writeText(promptEl.value);
            } catch (e) {
                promptEl.select();
                document.execCommand('copy');
            }
            successEl.classList.remove('hidden');
            btnCopy.innerText = '✓ Tersalin';
            setTimeout(() => {
                successEl.classList.add('hidden');
                btnCopy.innerText = '📋 Copy Prompt';
            }, 2000);
        });
    }

    // ── Filter pending list ──
    const search = document.getElementById('pending_search');
    if (search) {
        const items = document.querySelectorAll('.pending-item');
        const groups = document.querySelectorAll('.pending-group');
        const empty = document.getElementById('pending_empty');

        search.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            let totalVisible = 0;

            groups.forEach(g => {
                const groupItems = g.querySelectorAll('.pending-item');
                let groupVisible = 0;
                groupItems.forEach(it => {
                    const match = !q || it.dataset.search.includes(q);
                    it.style.display = match ? '' : 'none';
                    if (match) groupVisible++;
                });
                g.style.display = groupVisible > 0 ? '' : 'none';
                totalVisible += groupVisible;
            });

            if (empty) empty.classList.toggle('hidden', totalVisible > 0);
        });
    }
});
</script>
@endsection
