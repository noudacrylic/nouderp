@extends('layouts.erp')

@section('content')
@php
    $judul = match($tahap) {
        'belum-siap'  => ['Belum Siap', 'Produksi belum selesai atau stok fisik belum cukup — juga pesanan marketplace yang belum dibayar. Tombol proses sengaja dimatikan.'],
        'belum-lunas' => ['Belum Lunas', 'Barang sudah siap, tinggal menunggu pelunasan. Pesanan yang boleh dikirim sebelum lunas ditetapkan admin lewat tempo di form SO.'],
        'perlu-ukur'  => ['Perlu Ukur', 'Timbang & ukur kardus setelah dipacking supaya resi terbit dengan ongkir yang benar. Kolom sudah terisi taksiran — kalau sudah pas, simpan saja.'],
        default       => ['Siap Proses', 'Klik "Proses" untuk generate Faktur + Surat Jalan (kode booking wajib bila ambil di toko).'],
    };
@endphp
<div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="text-lg font-semibold">{{ $judul[0] }}</h1>
        <p class="text-xs text-gray-500">{{ $judul[1] }}</p>
    </div>
    {{-- Tarik manual pesanan marketplace terbaru (tanpa menunggu sinkron terjadwal). --}}
    <form method="POST" action="{{ route('pos.fulfillment.sync-marketplace') }}">
        @csrf
        <button type="submit" class="text-xs px-3 py-2 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">
            🔄 Tarik Pesanan Baru
        </button>
    </form>
</div>

@include('erp.pos.fulfillment._subtabs', ['items' => [
    [
        'label'  => 'Belum Siap',
        'url'    => route('pos.fulfillment.perlu-diproses', ['tahap' => 'belum-siap']),
        'active' => $tahap === 'belum-siap',
        'count'  => $counts['belum_siap'] ?? 0,
    ],
    [
        'label'  => 'Belum Lunas',
        'url'    => route('pos.fulfillment.perlu-diproses', ['tahap' => 'belum-lunas']),
        'active' => $tahap === 'belum-lunas',
        'count'  => $counts['belum_lunas'] ?? 0,
    ],
    [
        'label'  => 'Perlu Ukur',
        'url'    => route('pos.fulfillment.perlu-diproses', ['tahap' => 'perlu-ukur']),
        'active' => $tahap === 'perlu-ukur',
        'count'  => $counts['perlu_ukur'] ?? 0,
    ],
    [
        'label'   => 'Siap Proses',
        'url'     => route('pos.fulfillment.perlu-diproses'),
        'active'  => $tahap === 'siap',
        'count'   => $counts['perlu_diproses'] ?? 0,
        'instant' => $counts['instant'] ?? 0,
        'pickup'  => $counts['pickup'] ?? 0,
    ],
]])

@include('erp.pos.fulfillment._filters', ['couriers' => $couriers])

{{-- Aksi massal & tombol proses ulang hanya relevan di sub-tab "Siap Proses". --}}
@if($tahap === 'siap')
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
    <span id="bulkCount" class="text-xs font-semibold text-indigo-700">0 dipilih</span>
    <div class="ml-auto flex items-center gap-2 flex-wrap">
        <button type="button" id="bulkSelectAll" data-all="0" class="text-xs px-3 py-1.5 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">☑ Pilih semua</button>
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
@endif

<div class="space-y-5">
    @forelse($rows as $row)
        @if($row['kind'] === 'garansi')
            <div data-so-number="{{ $row['number'] }}" class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-rose-400 shadow-md hover:shadow-lg transition-shadow p-4">
                <div class="flex items-start justify-between gap-3">
                    @include('erp.pos.fulfillment._card_top', ['row' => $row])
                    @if($tahap === 'siap')
                        <a href="{{ route('sales.warranty.show', $row['id']) }}"
                           class="shrink-0 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-bold">Proses Garansi →</a>
                    @else
                        <span class="shrink-0 px-2 py-0.5 rounded text-[11px] bg-gray-100 text-gray-500">⏳ {{ $row['reason'] }}</span>
                    @endif
                </div>
            </div>
        @elseif($row['kind'] === 'mp_pending')
            {{-- Pesanan marketplace yang belum jadi SO (belum dibayar) — kartu info read-only. --}}
            <div data-so-number="{{ $row['number'] }}" class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-amber-400 shadow-md p-4">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700">🛒 {{ $row['channel'] }}</span>
                    @php $copyNumber = marketplace_copy_number($row['number'], true); @endphp
                    <span class="js-copy text-sm font-bold text-gray-800 cursor-pointer hover:text-indigo-600" data-copy="{{ $copyNumber }}" title="Klik untuk salin nomor (tanpa prefix channel)">{{ $row['number'] }}</span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700">⏳ Belum jadi SO</span>
                    <span class="ml-auto text-xs text-gray-500 whitespace-nowrap">
                        {{ $row['date'] ? \Carbon\Carbon::parse($row['date'])->format('d M Y') : '' }}
                    </span>
                </div>
                <div class="mt-2 flex items-center gap-x-4 gap-y-1 flex-wrap text-[13px] text-gray-600">
                    <span class="font-bold text-gray-800">{{ $row['customer'] }}</span>
                    @if($row['item_count'])<span class="text-gray-400">{{ $row['item_count'] }} item</span>@endif
                    <span>Total <b class="text-gray-800">Rp {{ number_format($row['grand_total'], 0, ',', '.') }}</b></span>
                </div>
                <div class="mt-1.5">
                    <span class="inline-block px-2 py-0.5 rounded text-[11px] bg-gray-100 text-gray-500">⏳ {{ $row['reason'] }}</span>
                </div>
            </div>
        @else
            @include('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => $bucket])
        @endif
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">
            {{ $tahap === 'siap' ? 'Tidak ada pesanan yang siap diproses.' : 'Tidak ada pesanan di tahap ini.' }}
        </div>
    @endforelse
</div>

@include('erp.sales.payment._midtrans_modals')
@include('erp.pos.fulfillment._seller_notes_js')
@include('erp.pos.fulfillment._copy_js')
@include('erp.pos.fulfillment._fokus_js')

@if($tahap === 'siap')
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
        const allSelected = all.length > 0 && sel.length === all.length;
        selectAll.dataset.all = allSelected ? '1' : '0';
        selectAll.textContent = allSelected ? '☒ Batal pilih' : '☑ Pilih semua';
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-bulk-check')) refresh();
    });

    // Tombol (bukan lagi checkbox) — hindari salah klik. Toggle: pilih semua ⇄ batal pilih.
    selectAll.addEventListener('click', function () {
        const target = selectAll.dataset.all !== '1';
        checks().forEach(c => { c.checked = target; });
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
        const tertahan = sel.filter(c => c.dataset.canprocess !== '1').length;
        const pickup   = sel.filter(c => c.dataset.pickup === '1').length;
        let warn = '';
        if (tertahan) warn += `\n• ${tertahan} belum boleh diproses akan dilewati.`;
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
@endif
@endsection
