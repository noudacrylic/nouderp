@extends('layouts.erp')

@section('content')
@php
    $judul = match($tahap) {
        'belum-siap'  => ['Belum Siap', 'Uangnya sudah masuk (DP atau lunas) tapi barangnya belum ada: produksi belum selesai atau stok fisik belum cukup. Tombol proses sengaja dimatikan.'],
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

{{-- Panel progres proses massal. Pesanan ditembak SATU PER SATU dari browser supaya tiap
     request selesai jauh di bawah timeout nginx (60 dtk) — batch besar dulu menembus batas
     itu dan memunculkan "502", lalu operator menekan Proses lagi di atas proses yang masih
     berjalan sehingga Jubelio kebanjiran. Di sini progres terlihat, jadi tak ada lagi tebak-
     tebakan apakah prosesnya masih jalan. --}}
<div id="bulkProgressOverlay" class="hidden fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[85vh] flex flex-col">
        <div class="px-4 py-3 border-b border-gray-100">
            <div class="flex items-center justify-between gap-3">
                <h3 id="bulkProgressTitle" class="text-sm font-bold text-gray-800">Memproses pesanan…</h3>
                <span id="bulkProgressCounter" class="text-xs font-semibold text-indigo-700">0/0</span>
            </div>
            <div class="mt-2 h-2 rounded-full bg-gray-100 overflow-hidden">
                <div id="bulkProgressBar" class="h-full bg-indigo-500 transition-all duration-300" style="width:0%"></div>
            </div>
            <p id="bulkProgressHint" class="mt-2 text-[11px] text-gray-400">
                Jangan tutup tab ini sampai selesai. Jeda 1,5 detik antar pesanan agar Jubelio tidak kebanjiran.
            </p>
        </div>

        <div id="bulkProgressList" class="flex-1 overflow-y-auto px-4 py-3 space-y-1.5 text-xs"></div>

        <div class="px-4 py-3 border-t border-gray-100 flex items-center gap-2 flex-wrap">
            <button type="button" id="bulkProgressRetry"
                    class="hidden text-xs px-3 py-1.5 rounded border border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100 font-semibold">
                ↻ Ulangi yang gagal
            </button>
            <button type="button" id="bulkProgressPrintResi"
                    class="hidden text-xs px-3 py-1.5 rounded border border-indigo-300 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 font-semibold">
                🖨 Cetak Resi Marketplace
            </button>
            <button type="button" id="bulkProgressPrintSj"
                    class="hidden text-xs px-3 py-1.5 rounded border border-indigo-300 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 font-semibold">
                🖨 Cetak Surat Jalan
            </button>
            <button type="button" id="bulkProgressStop"
                    class="ml-auto text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">
                Hentikan
            </button>
            <button type="button" id="bulkProgressClose"
                    class="hidden ml-auto text-xs px-3 py-1.5 rounded bg-gray-800 text-white hover:bg-gray-700 font-semibold">
                Selesai &amp; muat ulang
            </button>
        </div>
    </div>
</div>
@endif

<div class="space-y-5">
    @forelse($rows as $row)
        @include('erp.pos.fulfillment._row', [
            'row'                => $row,
            'mode'               => $bucket,
            'warrantyActionable' => $tahap === 'siap',
        ])
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

    // ── Proses massal: SATU request per pesanan, berurutan. ─────────────────────────────
    // Dulu seluruh centang dikirim dalam satu request. Tiap pesanan marketplace butuh 6–9
    // panggilan HTTP berurutan ke Jubelio (5–15 dtk), jadi batch >4 pesanan menembus
    // fastcgi_read_timeout nginx (60 dtk) → operator melihat "502" padahal proses di server
    // MASIH jalan (timer PHP tak menghitung waktu tunggu jaringan), lalu menekan Proses lagi
    // sehingga beberapa proses menumpuk dan membanjiri Jubelio sampai membalas HTTP 500.
    // Satu-per-satu + jeda menghapus kedua sebab itu sekaligus, dan progresnya kelihatan.
    // Route::has() — bukan route() langsung — supaya halaman ini TIDAK 500 selama route cache
    // produksi belum di-rebuild setelah deploy. Selama route baru belum ada di cache, tombol
    // otomatis memakai jalur form lama; begitu `optimize` dijalankan, jalur AJAX hidup sendiri.
    // URL relatif (absolute:false) — fetch() dari halaman https ke URL http akan diblokir
    // browser sebagai mixed content, dan host akses bisa berbeda (domain publik lewat
    // Cloudflare vs IP LAN). Path relatif selalu ikut host & skema halaman yang sedang dibuka.
    const ajaxUrl  = @json(\Route::has('pos.fulfillment.proses-ajax') ? route('pos.fulfillment.proses-ajax', ['so' => '__SO__'], false) : null);
    const resiUrl  = @json(route('pos.fulfillment.jubelio-resi-bulk'));
    const sjUrl    = @json(route('sales.deliveries.print-bulk'));
    const csrf     = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const JEDA_MS  = 1500;

    const ov       = document.getElementById('bulkProgressOverlay');
    const ovList   = document.getElementById('bulkProgressList');
    const ovBar    = document.getElementById('bulkProgressBar');
    const ovCount  = document.getElementById('bulkProgressCounter');
    const ovTitle  = document.getElementById('bulkProgressTitle');
    const ovHint   = document.getElementById('bulkProgressHint');
    const btnRetry = document.getElementById('bulkProgressRetry');
    const btnResi  = document.getElementById('bulkProgressPrintResi');
    const btnSj    = document.getElementById('bulkProgressPrintSj');
    const btnStop  = document.getElementById('bulkProgressStop');
    const btnClose = document.getElementById('bulkProgressClose');

    let stopDiminta = false, gagal = [], soResi = [], sjIds = [];

    const tidur = ms => new Promise(r => setTimeout(r, ms));

    function tambahBaris(item) {
        const el = document.createElement('div');
        el.className = 'flex items-start gap-2';
        el.innerHTML = '<span class="w-4 shrink-0">⏳</span>'
                     + '<span class="font-mono text-[11px] text-gray-500 shrink-0"></span>'
                     + '<span class="flex-1 text-gray-400"></span>';
        el.children[1].textContent = item.label;
        ovList.appendChild(el);
        return el;
    }

    function tandai(el, ikon, teks, warna) {
        el.children[0].textContent = ikon;
        el.children[2].textContent = teks;
        el.children[2].className = 'flex-1 ' + warna;
    }

    async function jalankan(items) {
        stopDiminta = false;
        gagal = [];
        ov.classList.remove('hidden');
        ovList.innerHTML = '';
        ovBar.style.width = '0%';
        ovCount.textContent = '0/' + items.length;
        btnRetry.classList.add('hidden');
        btnResi.classList.add('hidden');
        btnSj.classList.add('hidden');
        btnClose.classList.add('hidden');
        btnStop.classList.remove('hidden');
        btnStop.textContent = 'Hentikan';
        ovHint.classList.remove('hidden');

        const rows = items.map(it => ({ it, el: tambahBaris(it) }));
        let n = 0;

        for (const { it, el } of rows) {
            if (stopDiminta) { tandai(el, '⏸', 'dilewati — dihentikan operator', 'text-gray-400'); continue; }

            ovTitle.textContent = 'Memproses ' + it.label + '…';
            tandai(el, '⏳', 'sedang diproses…', 'text-indigo-500');
            el.scrollIntoView({ block: 'nearest' });

            let data;
            try {
                const res = await fetch(ajaxUrl.replace('__SO__', it.id), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                // 419 = sesi kedaluwarsa (tab dibiarkan terbuka lama), 504 = pesanan ini
                // sendiri melebihi batas waktu nginx. Keduanya membalas HTML, bukan JSON,
                // jadi sebutkan sebabnya alih-alih memaksa parse dan bilang "koneksi putus".
                if (res.status === 419) {
                    data = { ok: false, message: 'Sesi kedaluwarsa — muat ulang halaman lalu ulangi.' };
                } else if (res.status === 504 || res.status === 502) {
                    data = { ok: false, message: 'Server terlalu lama menunggu Jubelio untuk pesanan ini — coba Ulangi.' };
                } else {
                    data = await res.json();
                }
            } catch (e) {
                data = { ok: false, message: 'Koneksi ke server terputus — coba ulangi.' };
            }

            n++;
            ovCount.textContent = n + '/' + rows.length;
            ovBar.style.width = Math.round(n / rows.length * 100) + '%';

            if (data.ok) {
                // delivery_id terisi = jalur invoice ERP (punya Surat Jalan sendiri);
                // kosong = pesanan marketplace, resinya dicetak lewat label Jubelio.
                if (data.delivery_id) sjIds.push(data.delivery_id); else soResi.push(it.id);
                tandai(el,
                    data.resi_gagal ? '⚠' : '✅',
                    data.tracking_no ? ('resi ' + data.tracking_no) : (data.message || 'diproses'),
                    data.resi_gagal ? 'text-amber-600' : 'text-green-600');
            } else {
                tandai(el, '❌', data.message || 'gagal diproses', 'text-red-600');
                gagal.push(it);
            }

            if (n < rows.length && !stopDiminta) await tidur(JEDA_MS);
        }

        const sukses = rows.length - gagal.length;
        ovTitle.textContent = stopDiminta
            ? `Dihentikan — ${sukses} dari ${rows.length} selesai`
            : `Selesai — ${sukses} dari ${rows.length} berhasil`;
        ovHint.classList.add('hidden');
        btnStop.classList.add('hidden');
        btnClose.classList.remove('hidden');
        if (gagal.length)  btnRetry.classList.remove('hidden');
        if (soResi.length) btnResi.classList.remove('hidden');
        if (sjIds.length)  btnSj.classList.remove('hidden');
    }

    btnStop.addEventListener('click', () => { stopDiminta = true; btnStop.textContent = 'Menghentikan…'; });
    btnClose.addEventListener('click', () => window.location.reload());
    btnRetry.addEventListener('click', () => jalankan(gagal.slice()));
    btnResi.addEventListener('click', () => window.open(resiUrl + '?so=' + soResi.join(','), '_blank'));
    btnSj.addEventListener('click',   () => window.open(sjUrl + '?ids=' + sjIds.join(','), '_blank'));

    function submitProses() {
        const sel = selected();
        if (!sel.length) return;
        const tertahan = sel.filter(c => c.dataset.canprocess !== '1').length;
        const pickup   = sel.filter(c => c.dataset.pickup === '1').length;
        let warn = '';
        if (tertahan) warn += `\n• ${tertahan} belum boleh diproses akan dilewati.`;
        if (pickup)   warn += `\n• ${pickup} ambil-di-toko butuh kode booking (proses satu-per-satu).`;
        // Resi ikut terbit di sini (order pengiriman nyata) — sebutkan supaya jumlah paket
        // yang akan dibayar terlihat SEBELUM diklik, bukan sesudah saldo terpotong.
        if (!confirm(`Proses ${sel.length} pesanan? Faktur + Surat Jalan + RESI diterbitkan otomatis (memotong saldo kurir).${warn}`)) return;

        // Route AJAX belum masuk route cache (deploy baru, `optimize` belum dijalankan) →
        // pakai jalur lama supaya tombol tidak pernah mati.
        if (!ajaxUrl) { submitFormLama(sel); return; }

        soResi = []; sjIds = [];
        jalankan(sel.map(c => ({ id: c.value, label: c.dataset.number || ('#' + c.value) })));
    }

    function submitFormLama(sel) {
        const form = document.getElementById('bulkProsesForm');
        const box  = document.getElementById('bulkIdsContainer');
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
