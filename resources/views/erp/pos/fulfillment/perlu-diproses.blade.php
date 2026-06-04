@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">Perlu Diproses</h1>
        <p class="text-xs text-gray-500">Pesanan siap diproses. Klik "Proses" untuk generate Invoice + Surat Jalan (wajib lunas{{ '' }} & kode booking bila ambil di toko).</p>
    </div>
</div>

@if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>@endif
@if(session('success'))<div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>@endif

<form method="GET" class="mb-3">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari nomor / customer…"
           class="border rounded px-3 py-2 text-sm w-72">
</form>

{{-- Bar aksi massal (muncul saat ≥1 pesanan dicentang) --}}
<div id="bulkBar" class="hidden sticky top-0 z-20 mb-3 bg-white border border-indigo-200 rounded-xl shadow-sm px-3 py-2 flex items-center gap-3 flex-wrap">
    <label class="flex items-center gap-1.5 text-xs text-gray-600 cursor-pointer">
        <input type="checkbox" id="bulkSelectAll" class="w-4 h-4 accent-indigo-600"> Pilih semua
    </label>
    <span id="bulkCount" class="text-xs font-semibold text-indigo-700">0 dipilih</span>
    <div class="ml-auto flex items-center gap-2 flex-wrap">
        <button type="button" id="bulkPrintSo" class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</button>
        <button type="button" id="bulkProses" class="text-xs px-3 py-1.5 rounded border border-green-300 text-green-700 hover:bg-green-50 font-semibold">✅ Proses</button>
        <button type="button" id="bulkProsesResi" class="text-xs px-3 py-1.5 rounded border border-emerald-300 text-emerald-700 hover:bg-emerald-50 font-semibold">✅ Proses + Resi</button>
        <button type="button" id="bulkClear" class="text-xs px-2.5 py-1.5 rounded text-gray-400 hover:text-gray-600">Batal</button>
    </div>
</div>

<form id="bulkProsesForm" method="POST" action="{{ route('pos.fulfillment.proses-bulk') }}" class="hidden">
    @csrf
    <input type="hidden" name="print_after" id="bulkPrintAfter" value="0">
    <div id="bulkIdsContainer"></div>
</form>

<div class="space-y-5">
    @forelse($rows as $row)
        @if($row['kind'] === 'garansi')
            <div class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-rose-400 shadow-md hover:shadow-lg transition-shadow p-4">
                <div class="flex items-start justify-between gap-3">
                    @include('erp.pos.fulfillment._card_top', ['row' => $row])
                    <a href="{{ route('sales.warranty.show', $row['id']) }}"
                       class="shrink-0 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-bold">Proses Garansi →</a>
                </div>
            </div>
        @else
            @include('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => 'perlu_diproses'])
        @endif
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">Tidak ada pesanan yang perlu diproses.</div>
    @endforelse
</div>

@include('erp.sales.payment._midtrans_modals')
@include('erp.pos.fulfillment._seller_notes_js')
@include('erp.pos.fulfillment._copy_js')

<script>
// Aksi massal: pilih beberapa pesanan lalu Cetak SO / Proses / Proses + Resi sekaligus.
(function () {
    const bar = document.getElementById('bulkBar');
    if (!bar) return;
    const countEl   = document.getElementById('bulkCount');
    const selectAll = document.getElementById('bulkSelectAll');
    const printBase = @json(route('sales.orders.print-bulk'));

    const checks = () => Array.from(document.querySelectorAll('.js-bulk-check'));
    const selected = () => checks().filter(c => c.checked);

    function refresh() {
        const sel = selected();
        bar.classList.toggle('hidden', sel.length === 0);
        countEl.textContent = sel.length + ' dipilih';
        const all = checks();
        selectAll.checked = all.length > 0 && sel.length === all.length;
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-bulk-check')) refresh();
    });

    selectAll.addEventListener('change', function () {
        checks().forEach(c => { c.checked = selectAll.checked; });
        refresh();
    });

    document.getElementById('bulkClear').addEventListener('click', function () {
        checks().forEach(c => { c.checked = false; });
        refresh();
    });

    document.getElementById('bulkPrintSo').addEventListener('click', function () {
        const ids = selected().map(c => c.value);
        if (!ids.length) return;
        window.location.href = printBase + '?ids=' + ids.join(',');
    });

    function submitProses(printAfter) {
        const sel = selected();
        if (!sel.length) return;
        const notLunas = sel.filter(c => c.dataset.lunas !== '1').length;
        const pickup   = sel.filter(c => c.dataset.pickup === '1').length;
        let warn = '';
        if (notLunas) warn += `\n• ${notLunas} belum lunas akan dilewati.`;
        if (pickup)   warn += `\n• ${pickup} ambil-di-toko butuh kode booking (proses satu-per-satu).`;
        if (!confirm(`Proses ${sel.length} pesanan${printAfter ? ' + cetak resi' : ''}?${warn}`)) return;

        const form = document.getElementById('bulkProsesForm');
        document.getElementById('bulkPrintAfter').value = printAfter ? '1' : '0';
        const box = document.getElementById('bulkIdsContainer');
        box.innerHTML = '';
        sel.forEach(c => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = c.value;
            box.appendChild(inp);
        });
        form.submit();
    }

    document.getElementById('bulkProses').addEventListener('click', () => submitProses(false));
    document.getElementById('bulkProsesResi').addEventListener('click', () => submitProses(true));
})();
</script>
@endsection
