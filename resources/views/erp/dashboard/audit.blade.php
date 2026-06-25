@extends('layouts.erp')

@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $tgl = fn ($d) => $d ? \Carbon\Carbon::parse($d)->isoFormat('D MMM Y') : '—';

    // Badge status untuk daftar dokumen (Sales Order / Faktur).
    $statusBadge = [
        'posted'    => 'bg-emerald-100 text-emerald-700',
        'confirmed' => 'bg-blue-100 text-blue-700',
        'draft'     => 'bg-gray-100 text-gray-600',
        'partial'   => 'bg-amber-100 text-amber-700',
        'completed' => 'bg-emerald-100 text-emerald-700',
    ];
@endphp

@section('content')
<div class="mb-4">
    <a href="{{ route('dashboard.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-indigo-600 mb-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Kembali ke Dashboard
    </a>
    <div class="flex items-end justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-lg font-semibold text-gray-800">{{ $audit['title'] }}</h1>
            <p class="text-xs text-gray-500">{{ $audit['subtitle'] }} · <span class="font-medium">{{ $audit['rangeLabel'] }}</span></p>
        </div>
        <div class="text-right">
            <div class="text-[11px] text-gray-400 uppercase tracking-wide">Total</div>
            <div class="text-2xl font-bold text-gray-900">{{ $rp($audit['total']) }}</div>
        </div>
    </div>
</div>

<div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 mb-4">
    🔎 Halaman audit: rincian pembentuk angka dashboard.
    @if($audit['type'] === 'documents')
        Klik nomor dokumen untuk membuka transaksi sumbernya.
    @else
        Klik nama akun untuk menelusuri seluruh baris jurnalnya di Buku Besar.
    @endif
</div>

{{-- Live search: filter baris tabel secara real-time (klien) tanpa reload. --}}
<div class="mb-3 flex items-center gap-2 flex-wrap">
    <div class="relative">
        <input type="text" id="auditSearch" autocomplete="off"
               placeholder="{{ $audit['type'] === 'documents' ? 'Cari nomor / pelanggan / status…' : 'Cari kode / nama akun…' }}"
               class="border rounded-lg pl-9 pr-3 py-2 text-sm w-80 focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
    </div>
    <span id="auditCount" class="text-xs text-gray-400"></span>
</div>

@if($audit['type'] === 'documents')
    {{-- ───────── Daftar dokumen sumber ───────── --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide text-left">
                        <th class="px-4 py-2.5 font-medium w-10">#</th>
                        @foreach($audit['columns'] as $i => $col)
                            <th class="px-4 py-2.5 font-medium {{ $i === count($audit['columns']) - 1 ? 'text-right' : '' }}">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($audit['rows'] as $i => $r)
                        <tr class="js-audit-row hover:bg-indigo-50/30">
                            <td class="px-4 py-2.5 text-gray-400 js-audit-idx">{{ $i + 1 }}</td>
                            <td class="px-4 py-2.5">
                                <a href="{{ $r['url'] }}" class="font-mono text-indigo-600 hover:underline">{{ $r['number'] }}</a>
                            </td>
                            <td class="px-4 py-2.5 text-gray-600 whitespace-nowrap">{{ $tgl($r['date']) }}</td>
                            <td class="px-4 py-2.5 text-gray-700">{{ $r['customer'] }}</td>
                            <td class="px-4 py-2.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-medium {{ $statusBadge[$r['status']] ?? 'bg-gray-100 text-gray-600' }}">{{ $r['status'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold text-gray-800 whitespace-nowrap">{{ $rp($r['amount']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($audit['columns']) + 1 }}" class="px-4 py-8 text-center text-gray-400">Tidak ada transaksi pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
                @if(count($audit['rows']))
                <tfoot>
                    <tr class="bg-gray-50 font-bold text-gray-800">
                        <td class="px-4 py-2.5" colspan="{{ count($audit['columns']) }}">Total ({{ count($audit['rows']) }} dokumen)</td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">{{ $rp($audit['total']) }}</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>

@else
    {{-- ───────── Ringkasan per akun (audit detail → Buku Besar akun) ───────── --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide text-left">
                        <th class="px-4 py-2.5 font-medium w-24">Kode</th>
                        <th class="px-4 py-2.5 font-medium">Akun</th>
                        <th class="px-4 py-2.5 font-medium text-right">Total Debit</th>
                        <th class="px-4 py-2.5 font-medium text-right">Total Kredit</th>
                        <th class="px-4 py-2.5 font-medium text-right">Saldo</th>
                        <th class="px-4 py-2.5 font-medium text-right w-32">Buku Besar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($audit['accounts'] as $acc)
                        <tr class="js-audit-row hover:bg-indigo-50/30">
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-500">{{ $acc['code'] }}</td>
                            <td class="px-4 py-2.5">
                                <a href="{{ $acc['ledger_url'] }}" class="text-gray-800 font-medium hover:text-indigo-600 hover:underline">{{ $acc['name'] }}</a>
                                <span class="text-xs text-gray-400">· {{ $acc['line_count'] }} jurnal</span>
                            </td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap text-gray-600">{{ $rp($acc['debit_total']) }}</td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap text-gray-600">{{ $rp($acc['credit_total']) }}</td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap font-semibold text-gray-900">{{ $rp($acc['balance']) }}</td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                <a href="{{ $acc['ledger_url'] }}" class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:underline">
                                    Lihat
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Tidak ada pergerakan jurnal pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
                @if(count($audit['accounts']))
                <tfoot>
                    <tr class="bg-gray-800 font-bold" style="color:#ffffff">
                        <td class="px-4 py-3" style="color:#ffffff" colspan="4">Total {{ $audit['title'] }} ({{ count($audit['accounts']) }} akun)</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap text-base" style="color:#ffffff" colspan="2">{{ $rp($audit['total']) }}</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>

    <p class="text-xs text-gray-400 mt-3">
        💡 Klik nama akun atau "Lihat" untuk menelusuri seluruh baris jurnal akun tersebut di Buku Besar (lengkap dgn saldo berjalan & dokumen sumber).
    </p>
@endif

<div id="auditNoResults" class="hidden bg-white rounded-lg shadow border border-gray-200 px-4 py-8 text-center text-gray-400 text-sm mt-3">
    Tidak ada baris yang cocok dengan pencarian.
</div>

<script>
// Live search: saring baris tabel audit (dokumen / akun) tanpa reload.
(function () {
    const input = document.getElementById('auditSearch');
    if (!input) return;
    const rows    = Array.from(document.querySelectorAll('.js-audit-row'));
    const countEl = document.getElementById('auditCount');
    const noRes   = document.getElementById('auditNoResults');
    const total   = rows.length;

    function apply() {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(r => {
            const match = !q || r.textContent.toLowerCase().includes(q);
            r.classList.toggle('hidden', !match);
            if (match) {
                shown++;
                const idx = r.querySelector('.js-audit-idx'); // nomor urut dokumen → rapikan
                if (idx) idx.textContent = shown;
            }
        });
        countEl.textContent = q ? `Menampilkan ${shown} dari ${total}` : `${total} baris`;
        if (noRes) noRes.classList.toggle('hidden', shown !== 0);
    }

    input.addEventListener('input', apply);
    apply();
})();
</script>
@endsection
