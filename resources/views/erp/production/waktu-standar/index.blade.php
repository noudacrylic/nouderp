@extends('layouts.erp')

@section('content')
@php
    $f = fn ($v, $d = 2) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $tanpaStandar = $usulan->filter(fn ($u) => $u['cukup'] && !$u['berlaku'])->count();
@endphp
<div class="w-full px-6 py-4" x-data="waktuStandar()">
    {{-- Kabar hasil coret / simpan per baris (tanpa muat ulang halaman) --}}
    <div x-show="pesan" x-transition x-cloak
         class="fixed top-4 right-4 z-50 px-4 py-2 rounded-xl shadow-lg text-sm font-semibold"
         :class="gagal ? 'bg-red-600 text-white' : 'bg-gray-900 text-white'" x-text="pesan"></div>
    <div class="mb-3">
        <h1 class="text-xl font-bold text-gray-800">Waktu Standar — {{ $divisi->firstWhere('id', $deptId)?->name }}</h1>
        <p class="text-xs text-gray-500 max-w-4xl">
            Menit kerja per pcs untuk tiap SKU — dasar target per OP: jatah waktu = jumlah pcs × waktu standar.
            Usulan = <b>rata-rata</b> catatan timer OP berisi 1 produk — ready stock maupun custom (produk custom bebas CS1–CS6 tidak dihitung).
            Catatan yang lebih cepat dari ½× atau lebih lambat dari {{ $f($faktor, 0) }}× median dianggap <b>tak wajar</b>
            (biasanya timer lupa dinyalakan/dijeda) dan tidak ikut dihitung. Buka <b>rincian</b> untuk <b>mencoret</b> sampel lain yang menyimpang —
            coretan hanya untuk hitungan standar, nilai karyawan di OP itu tetap dihitung.
        </p>
    </div>

    {{-- Saringan sumber usulan --}}
    <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-4 flex flex-wrap items-end gap-5 text-sm">
        <input type="hidden" name="divisi" value="{{ $deptId }}">
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Riwayat</label>
            <select name="hari" class="border border-gray-200 rounded-xl px-3 py-2">
                @foreach([60 => '2 bulan', 90 => '3 bulan', 150 => '5 bulan', 180 => '6 bulan', 365 => '1 tahun'] as $v => $l)
                    <option value="{{ $v }}" @selected($hari === $v)>{{ $l }} terakhir</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Catatan dari pelaksana <span class="font-normal text-gray-400">(bawaan: yang sudah lewat masa percobaan)</span></label>
            <div class="flex flex-wrap gap-3">
                @foreach($semuaPelaksana as $p)
                    <label class="inline-flex items-center gap-1.5 {{ $p->is_active ? '' : 'text-gray-400' }}">
                        <input type="checkbox" name="pelaksana[]" value="{{ $p->id }}" @checked(in_array($p->id, $pelaksana, true)) class="rounded">
                        {{ $p->name }}
                        @unless($p->is_active)<span class="text-[10px]">(nonaktif)</span>@endunless
                        @if($p->created_at && $p->created_at->gt(now()->subDays(\App\Modules\Production\Services\WaktuStandarService::HARI_PERCOBAAN)))
                            <span class="text-[10px] px-1 rounded bg-amber-50 text-amber-700">percobaan</span>
                        @endif
                    </label>
                @endforeach
            </div>
        </div>
        <button class="px-4 py-2 rounded-xl border border-gray-300 text-gray-700 hover:bg-gray-50 font-semibold">Terapkan</button>
    </form>

    <form method="POST" action="{{ route('production.waktu-standar.simpan') }}" x-ref="form">
        @csrf
        <input type="hidden" name="divisi" value="{{ $deptId }}">

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-4 py-2 border-b border-gray-100 text-xs text-gray-500">
                <span>{{ $usulan->count() }} SKU · {{ $usulan->where('cukup', true)->count() }} dengan ≥ {{ $minSampel }} catatan wajar</span>
                @if($tanpaStandar)
                    <button type="button" class="px-2.5 py-1 rounded-lg border border-blue-300 text-blue-700 hover:bg-blue-50 font-semibold"
                            @click="$refs.form.querySelectorAll('[data-usulan-cukup]').forEach(i => { if (!i.value) i.value = i.dataset.usulanCukup })">
                        Isi {{ $tanpaStandar }} SKU kosong dari usulan
                    </button>
                @endif
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="text-left px-4 py-2">Produk</th>
                        <th class="text-right px-3 py-2">Catatan</th>
                        <th class="text-right px-3 py-2">Usulan (rata-rata)</th>
                        <th class="text-right px-3 py-2">Rentang tengah</th>
                        <th class="text-right px-3 py-2">Standar berlaku</th>
                        <th class="text-left px-3 py-2 w-44">Standar baru (mnt/pcs)</th>
                        <th class="px-2 py-2"></th>
                    </tr>
                </thead>
                @forelse($usulan as $u)
                    @include('erp.production.waktu-standar._sku', ['u' => $u, 'buka' => (int) session('buka') === $u['product_id']])
                @empty
                    <tbody><tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">Belum ada catatan kerja di periode ini.</td></tr></tbody>
                @endforelse
            </table>
        </div>

        <div class="sticky bottom-0 mt-4 bg-white rounded-2xl border border-gray-200 shadow-lg p-4 flex flex-wrap items-end gap-4 text-sm">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Berlaku mulai</label>
                <input type="date" name="berlaku_mulai" value="{{ now()->firstOfQuarter()->toDateString() }}" required
                       class="border border-gray-200 rounded-xl px-3 py-2">
            </div>
            <div class="flex-1 min-w-64">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Catatan <span class="font-normal text-gray-400">(opsional, mis. "tinjauan Q4")</span></label>
                <input type="text" name="catatan" maxlength="255" class="w-full border border-gray-200 rounded-xl px-3 py-2">
            </div>
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-xl font-semibold">Simpan waktu standar</button>
            <p class="w-full text-[11px] text-gray-400">
                Hanya SKU yang angkanya berubah yang disimpan. Tanggal berlaku sama → angka lama dikoreksi; tanggal baru → standar lama tetap tersimpan
                sehingga nilai bulan sebelumnya tetap memakai standar saat itu. Bawaan: awal kuartal ini.
                Tombol <b>simpan</b> di tiap baris menyimpan SKU itu saja dengan tanggal berlaku di sini.
            </p>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
function waktuStandar() {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    return {
        pesan: '', gagal: false, _t: null,
        kabar(teks, gagal = false) {
            this.pesan = teks; this.gagal = gagal;
            clearTimeout(this._t); this._t = setTimeout(() => this.pesan = '', gagal ? 6000 : 2500);
        },
        // Saringan halaman (hari, pelaksana) ikut dikirim supaya usulan baris dihitung sama.
        dasar() {
            const fd = new FormData();
            new URLSearchParams(location.search).forEach((v, k) => fd.append(k, v));
            if (!fd.has('divisi')) fd.append('divisi', '{{ $deptId }}');
            return fd;
        },
        async kirim(url, fd) {
            const r = await fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(j.message || 'Gagal (' + r.status + ')');
            return j;
        },
        ganti(pid, html, pertahankanKetikan) {
            const lama = document.getElementById('sku-' + pid);
            if (!lama || !html) return;
            const ketik = lama.querySelector('[data-menit]')?.value;
            const t = document.createElement('table');
            t.innerHTML = html.trim();
            const baru = t.querySelector('tbody');
            lama.replaceWith(baru);
            if (pertahankanKetikan && ketik) baru.querySelector('[data-menit]').value = ketik;
        },
        async coret(stepId, coret, pid) {
            const fd = this.dasar();
            fd.append('coret', coret); fd.append('buka', pid);
            try {
                const j = await this.kirim(@js(url('erp/production/waktu-standar/sampel')) + '/' + stepId + '/coret', fd);
                this.ganti(pid, j.html, true);
                this.kabar(j.pesan);
            } catch (e) { this.kabar(e.message, true); }
        },
        async simpanBaris(pid) {
            const baris = document.getElementById('sku-' + pid);
            const menit = baris.querySelector('[data-menit]').value.trim();
            if (!menit) { this.kabar('Isi dulu angka standarnya.', true); return; }
            const fd = this.dasar();
            fd.append('berlaku_mulai', this.$refs.form.querySelector('[name=berlaku_mulai]').value);
            fd.append('catatan', this.$refs.form.querySelector('[name=catatan]').value);
            fd.append(`standar[${pid}][menit]`, menit);
            fd.append(`standar[${pid}][usulan]`, baris.querySelector('[data-usulan]').value);
            fd.append(`standar[${pid}][sampel]`, baris.querySelector('[data-sampel]').value);
            try {
                const j = await this.kirim(@js(route('production.waktu-standar.simpan')), fd);
                this.ganti(pid, j.html, false);
                this.kabar(j.pesan);
            } catch (e) { this.kabar(e.message, true); }
        },
    };
}
</script>
@endpush
