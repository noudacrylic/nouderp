@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">Telah Diproses</h1>
        <p class="text-xs text-gray-500">Sudah diproses, menunggu penyelesaian: generate resi (non-marketplace) / transaksi marketplace selesai. Setelah resi terbit atau transaksi selesai → pindah ke "Selesai".</p>
    </div>
</div>


@include('erp.pos.fulfillment._filters', ['couriers' => $couriers])

{{-- Bar aksi massal (muncul saat ≥1 pesanan dicentang) — tanpa cek berat/dimensi, berdasarkan SO. --}}
<div id="tdBulkBar" class="hidden z-40 mb-3 bg-white border border-emerald-200 rounded-xl shadow-md px-3 py-2 flex items-center gap-3 flex-wrap">
    <label class="flex items-center gap-1.5 text-xs text-gray-600 cursor-pointer">
        <input type="checkbox" id="tdSelectAll" class="w-4 h-4 accent-emerald-600"> Pilih semua
    </label>
    <span id="tdCount" class="text-xs font-semibold text-emerald-700">0 dipilih</span>
    <div class="ml-auto flex items-center gap-2 flex-wrap">
        <button type="button" id="tdGenResi" class="text-xs px-3 py-1.5 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">📮 Generate Resi</button>
        <button type="button" id="tdPrintResi" class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🏷️ Cetak Resi</button>
        <button type="button" id="tdPrintLabel" class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🏷️ Cetak Label</button>
        <button type="button" id="tdPrintInv" class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🧾 Cetak Faktur</button>
        <button type="button" id="tdPrintSj" class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">📄 Cetak Surat Jalan</button>
        <button type="button" id="tdClear" class="text-xs px-2.5 py-1.5 rounded text-gray-400 hover:text-gray-600">Batal</button>
    </div>
</div>

<form id="tdBookForm" method="POST" action="{{ route('pos.fulfillment.book-bulk') }}" class="hidden">
    @csrf
    <div id="tdBookIds"></div>
</form>

<div class="space-y-5">
    @forelse($rows as $row)
        @if($row['kind'] === 'garansi')
            @php $gd = $row['delivery'] && $row['delivery']->status === 'posted' ? $row['delivery'] : null; @endphp
            <div class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-rose-400 shadow-md hover:shadow-lg transition-shadow p-4">
                <div class="flex items-start justify-between gap-3">
                    @include('erp.pos.fulfillment._card_top', ['row' => $row])
                    <span class="text-xs text-gray-500 shrink-0">{{ $row['status_label'] }}</span>
                </div>
                @if($gd)
                    <div class="mt-3 border-t border-gray-50 pt-3 flex items-center justify-between gap-2 flex-wrap text-xs bg-gray-50/70 rounded-lg px-3 py-1.5">
                        <span class="font-semibold text-gray-700">📄 <span class="js-copy cursor-pointer hover:text-indigo-600" data-copy="{{ $gd->delivery_number }}" title="Klik untuk salin nomor SJ">{{ $gd->delivery_number }}</span></span>
                        <a href="{{ route('sales.deliveries.print', $gd->id) }}"
                           class="px-2.5 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">Cetak SJ</a>
                    </div>
                @endif
            </div>
        @else
            @include('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => 'telah_diproses'])
        @endif
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">Belum ada pesanan yang diproses.</div>
    @endforelse
</div>

@include('erp.pos.fulfillment._seller_notes_js')
@include('erp.pos.fulfillment._copy_js')
@include('erp.pos.fulfillment._bulk_sticky_js', ['barId' => 'tdBulkBar'])

{{-- ===== POPUP GENERATE RESI: cek ulang berat & dimensi + ongkir ===== --}}
<div id="gr_modal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/40 p-4">
    <form id="gr_form" method="POST" action="" class="bg-white rounded-xl shadow-2xl w-[460px] max-w-[95vw]"
          onsubmit="return confirm('Generate resi (booking kurir) sekarang? Ini membuat order pengiriman nyata.')">
        @csrf
        <div class="flex items-center justify-between px-4 py-3 border-b">
            <h6 class="font-bold text-gray-800 text-sm">📮 Generate Resi — <span id="gr_num" class="font-mono"></span></h6>
            <button type="button" id="gr_close" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <div class="p-4 space-y-4">
            <div class="text-xs text-gray-500">Kurir: <b id="gr_courier" class="text-gray-700">—</b></div>
            <div>
                <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Berat (gram)</label>
                <input type="number" name="weight_gram" id="gr_weight" min="1" step="1"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Dimensi Paket (cm) — opsional</label>
                <div class="flex items-center gap-2">
                    <input type="number" name="package_length" id="gr_len" min="0" step="0.01" placeholder="P"
                           class="border border-gray-200 rounded-lg px-2 py-2 text-sm w-full text-center">
                    <span class="text-gray-400">×</span>
                    <input type="number" name="package_width" id="gr_wid" min="0" step="0.01" placeholder="L"
                           class="border border-gray-200 rounded-lg px-2 py-2 text-sm w-full text-center">
                    <span class="text-gray-400">×</span>
                    <input type="number" name="package_height" id="gr_hei" min="0" step="0.01" placeholder="T"
                           class="border border-gray-200 rounded-lg px-2 py-2 text-sm w-full text-center">
                </div>
                <p class="text-[11px] text-gray-400 mt-1">Isi dimensi untuk kurir instant (Pickup/Van) & barang besar (volumetrik).</p>
            </div>
            <div class="bg-gray-50 border border-gray-100 rounded-lg px-3 py-2 flex items-center justify-between gap-2">
                <span id="gr_price" class="text-sm text-gray-500">Menghitung ongkir…</span>
                <button type="button" id="gr_recalc" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-xs font-bold whitespace-nowrap">Hitung Ulang</button>
            </div>
        </div>
        <div class="flex justify-end gap-2 px-4 py-3 border-t bg-gray-50">
            <button type="button" id="gr_cancel" class="px-4 py-2 text-sm font-bold text-gray-500 hover:bg-gray-100 rounded-lg">Batal</button>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 text-sm font-bold rounded-lg">📮 Generate Resi</button>
        </div>
    </form>
</div>

<script>
(function () {
    var bookTpl = @json(route('sales.deliveries.book', ['id' => '__ID__']));
    var infoTpl = @json(route('sales.deliveries.ship-info', ['id' => '__ID__']));
    var modal = document.getElementById('gr_modal');
    var form  = document.getElementById('gr_form');
    if (!modal || !form) return;

    var elW = document.getElementById('gr_weight'), elL = document.getElementById('gr_len'),
        elWd = document.getElementById('gr_wid'), elH = document.getElementById('gr_hei');
    var out = document.getElementById('gr_price');
    var cfg = {};

    function fmt(n){ return Math.round(n).toLocaleString('id-ID'); }
    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }

    function cek() {
        if (!cfg.area) { out.textContent = 'Cek ongkir live butuh area customer. Lengkapi alamat (area) di SO.'; out.className = 'text-sm text-amber-600'; return; }
        var p = new URLSearchParams({
            warehouse_id: cfg.warehouse_id,
            destination_area_id: cfg.area,
            weight_gram: Math.max(1, parseInt(elW.value || '1', 10)),
            mode: cfg.mode,
        });
        if (cfg.dest_lat != null && cfg.dest_lng != null) { p.set('destination_latitude', cfg.dest_lat); p.set('destination_longitude', cfg.dest_lng); }
        if (parseFloat(elL.value) > 0) p.set('package_length', elL.value);
        if (parseFloat(elWd.value) > 0) p.set('package_width', elWd.value);
        if (parseFloat(elH.value) > 0) p.set('package_height', elH.value);

        out.textContent = 'Mengambil ongkir…'; out.className = 'text-sm text-gray-400';
        fetch('/erp/api/shipping/rates?' + p.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success || !d.rates || !d.rates.length) {
                    out.textContent = (d.errors && d.errors.length) ? d.errors.join(' ') : 'Tidak ada layanan ongkir untuk berat/dimensi ini.';
                    out.className = 'text-sm text-amber-600';
                    return;
                }
                var match = d.rates.find(function (x) {
                    return (x.courier_code || '') === cfg.courier_code && (!cfg.service_code || (x.service_code || '') === cfg.service_code);
                }) || d.rates.find(function (x) { return (x.courier_code || '') === cfg.courier_code; });
                if (!match) {
                    out.textContent = 'Layanan ' + (cfg.courier_code || '').toUpperCase() + ' tidak tersedia untuk berat/dimensi ini.';
                    out.className = 'text-sm text-amber-600';
                    return;
                }
                out.innerHTML = (match.service_name || '') + ': <b class="text-gray-800">Rp ' + fmt(match.price || 0) + '</b>'
                    + (match.etd ? ' <span class="text-gray-400">· ' + match.etd + '</span>' : '');
                out.className = 'text-sm text-gray-600';
            })
            .catch(function () { out.textContent = 'Gagal cek ongkir.'; out.className = 'text-sm text-red-500'; });
    }

    var t = null;
    function schedule() { clearTimeout(t); t = setTimeout(cek, 500); }
    [elW, elL, elWd, elH].forEach(function (el) { if (el) el.addEventListener('input', schedule); });
    document.getElementById('gr_recalc').addEventListener('click', cek);
    document.getElementById('gr_close').addEventListener('click', closeModal);
    document.getElementById('gr_cancel').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    document.querySelectorAll('.js-genresi').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.dataset.id;
            form.action = bookTpl.replace('__ID__', id);
            document.getElementById('gr_num').textContent = this.dataset.number || '';
            out.textContent = 'Memuat data…'; out.className = 'text-sm text-gray-400';
            openModal();
            fetch(infoTpl.replace('__ID__', id), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    cfg = d;
                    elW.value = d.weight_gram || 1;
                    elL.value = d.package_length || '';
                    elWd.value = d.package_width || '';
                    elH.value = d.package_height || '';
                    document.getElementById('gr_courier').textContent = d.courier_label || '—';
                    cek();
                })
                .catch(function () { out.textContent = 'Gagal memuat data pengiriman.'; out.className = 'text-sm text-red-500'; });
        });
    });
})();
</script>

<script>
// Aksi massal di Telah Diproses: Generate Resi / Cetak Resi / Cetak Faktur / Cetak Surat Jalan.
(function () {
    const bar = document.getElementById('tdBulkBar');
    if (!bar) return;
    const countEl   = document.getElementById('tdCount');
    const selectAll = document.getElementById('tdSelectAll');
    const resiBase  = @json(route('sales.deliveries.print-resi-bulk'));
    const labelBase = @json(route('sales.deliveries.print-label-bulk'));
    const invBase   = @json(route('sales.invoices.print-bulk'));
    const sjBase    = @json(route('sales.deliveries.print-bulk'));
    const mpResiBase = @json(route('pos.fulfillment.jubelio-resi-bulk'));

    const checks = () => Array.from(document.querySelectorAll('.js-bulk-td'));
    const selected = () => checks().filter(c => c.checked);

    // Tombol berbasis Surat Jalan (Biteship/manual) tak berlaku utk marketplace → sembunyikan
    // saat seluruh pilihan adalah pesanan marketplace.
    function setShown(id, show) { const el = document.getElementById(id); if (el) el.classList.toggle('hidden', !show); }

    function refresh() {
        const sel = selected();
        bar.classList.toggle('hidden', sel.length === 0);
        countEl.textContent = sel.length + ' dipilih';
        const all = checks();
        selectAll.checked = all.length > 0 && sel.length === all.length;
        const hasNp = sel.some(c => c.dataset.mp !== '1');
        ['tdGenResi', 'tdPrintLabel', 'tdPrintInv', 'tdPrintSj'].forEach(id => setShown(id, hasNp));
    }

    // Kumpulkan ID dari atribut data (comma-separated) lintas card terpilih, dedupe.
    function collect(attr) {
        const set = new Set();
        selected().forEach(c => (c.dataset[attr] || '').split(',').forEach(v => {
            v = v.trim(); if (v) set.add(v);
        }));
        return Array.from(set);
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-bulk-td')) refresh();
    });
    selectAll.addEventListener('change', function () {
        checks().forEach(c => { c.checked = selectAll.checked; });
        refresh();
    });
    document.getElementById('tdClear').addEventListener('click', function () {
        checks().forEach(c => { c.checked = false; });
        refresh();
    });

    document.getElementById('tdGenResi').addEventListener('click', function () {
        const ids = collect('gen');
        if (!ids.length) { alert('Tidak ada Surat Jalan yang perlu di-generate resi pada pesanan terpilih (mungkin sudah ber-resi / ambil di toko).'); return; }
        if (!confirm('Generate resi untuk ' + ids.length + ' Surat Jalan? Ini membuat order pengiriman nyata (tanpa cek berat/dimensi — pakai data SO).')) return;
        const box = document.getElementById('tdBookIds');
        box.innerHTML = '';
        ids.forEach(id => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
            box.appendChild(inp);
        });
        document.getElementById('tdBookForm').submit();
    });

    function goPrint(base, ids, emptyMsg) {
        if (!ids.length) { alert(emptyMsg); return; }
        window.location.href = base + '?ids=' + ids.join(',');
    }
    // Cetak Resi: marketplace → URL report Jubelio gabungan; non-marketplace → label Biteship.
    document.getElementById('tdPrintResi').addEventListener('click', function () {
        const mpSo = new Set(), npResi = new Set();
        selected().forEach(c => {
            if (c.dataset.mp === '1') { if (c.dataset.so) mpSo.add(c.dataset.so); }
            else (c.dataset.resi || '').split(',').forEach(v => { v = v.trim(); if (v) npResi.add(v); });
        });
        if (mpSo.size && npResi.size) {
            alert('Pilih hanya pesanan marketplace ATAU non-marketplace untuk cetak resi bersamaan (format labelnya berbeda).');
            return;
        }
        if (mpSo.size) { window.location.href = mpResiBase + '?so=' + Array.from(mpSo).join(','); return; }
        goPrint(resiBase, Array.from(npResi), 'Tidak ada resi pada pesanan terpilih (belum di-generate).');
    });
    document.getElementById('tdPrintLabel').addEventListener('click', () =>
        goPrint(labelBase, collect('gen'), 'Tidak ada pengiriman tanpa resi pada pesanan terpilih (yang ber-resi pakai Cetak Resi).'));
    document.getElementById('tdPrintInv').addEventListener('click', () =>
        goPrint(invBase, collect('invoice'), 'Tidak ada faktur pada pesanan terpilih.'));
    document.getElementById('tdPrintSj').addEventListener('click', () =>
        goPrint(sjBase, collect('sj'), 'Tidak ada Surat Jalan pada pesanan terpilih.'));
})();
</script>
@endsection
