@extends('layouts.erp')

@section('content')
<div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="text-lg font-semibold">Perlu Diproses</h1>
        <p class="text-xs text-gray-500">Pesanan siap diproses. Klik "Proses" untuk generate Faktur + Surat Jalan (wajib lunas{{ '' }} & kode booking bila ambil di toko).</p>
    </div>
    {{-- Tarik manual pesanan marketplace terbaru (tanpa menunggu sinkron terjadwal). --}}
    <form method="POST" action="{{ route('pos.fulfillment.sync-marketplace') }}">
        @csrf
        <button type="submit" class="text-xs px-3 py-2 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">
            🔄 Tarik Pesanan Baru
        </button>
    </form>
</div>


@include('erp.pos.fulfillment._filters', ['couriers' => $couriers])

{{-- Tombol pintas: pilih semua pesanan yang GAGAL diproses untuk diproses ulang.
     Muncul hanya bila ada kegagalan (dihitung dari kartu via JS). --}}
<div id="failedBar" class="hidden mb-3 flex items-center gap-2 flex-wrap">
    <button type="button" id="btnSelectFailed"
            class="text-sm px-3 py-2 rounded border border-red-300 bg-red-50 text-red-700 hover:bg-red-100 font-semibold inline-flex items-center gap-2">
        ⚠ Gagal Proses
        <span id="failedCount" class="px-1.5 py-0.5 rounded-full bg-red-600 text-white text-xs font-bold leading-none">0</span>
    </button>
    <span class="text-xs text-gray-400">Klik untuk pilih pesanan yang gagal diproses, lalu tekan ✅ Proses untuk coba ulang.</span>
</div>

{{-- Bar aksi massal (muncul saat ≥1 pesanan dicentang) --}}
<div id="bulkBar" class="hidden z-40 mb-3 bg-white border border-indigo-200 rounded-xl shadow-md px-3 py-2 flex items-center gap-3 flex-wrap">
    <label class="flex items-center gap-1.5 text-xs text-gray-600 cursor-pointer">
        <input type="checkbox" id="bulkSelectAll" class="w-4 h-4 accent-indigo-600"> Pilih semua
    </label>
    <span id="bulkCount" class="text-xs font-semibold text-indigo-700">0 dipilih</span>
    <div class="ml-auto flex items-center gap-2 flex-wrap">
        <button type="button" id="bulkPrintSo" class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</button>
        <button type="button" id="bulkProses" class="text-xs px-3 py-1.5 rounded border border-green-300 text-green-700 hover:bg-green-50 font-semibold">✅ Proses</button>
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
@include('erp.pos.fulfillment._bulk_sticky_js', ['barId' => 'bulkBar'])

<script>
// Aksi massal: pilih beberapa pesanan lalu Cetak SO / Proses sekaligus.
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

    function submitProses() {
        const sel = selected();
        if (!sel.length) return;
        const notLunas = sel.filter(c => c.dataset.lunas !== '1').length;
        const pickup   = sel.filter(c => c.dataset.pickup === '1').length;
        let warn = '';
        if (notLunas) warn += `\n• ${notLunas} belum lunas akan dilewati.`;
        if (pickup)   warn += `\n• ${pickup} ambil-di-toko butuh kode booking (proses satu-per-satu).`;
        if (!confirm(`Proses ${sel.length} pesanan?${warn}`)) return;

        const form = document.getElementById('bulkProsesForm');
        const box = document.getElementById('bulkIdsContainer');
        box.innerHTML = '';
        sel.forEach(c => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = c.value;
            box.appendChild(inp);
        });
        form.submit();
    }

    document.getElementById('bulkProses').addEventListener('click', () => submitProses());

    // ── Tombol "Gagal Proses": tampil bila ada kartu gagal; klik → centang semuanya. ──
    const failedBar = document.getElementById('failedBar');
    const failed = () => checks().filter(c => c.dataset.failed === '1');
    const f = failed();
    if (f.length) {
        document.getElementById('failedCount').textContent = f.length;
        failedBar.classList.remove('hidden');
    }
    document.getElementById('btnSelectFailed').addEventListener('click', function () {
        const list = failed();
        if (!list.length) return;
        list.forEach(c => { c.checked = true; });
        refresh();                                   // tampilkan bulk bar + perbarui hitungan
        list[0].closest('.bg-white')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
})();
</script>
@endsection
