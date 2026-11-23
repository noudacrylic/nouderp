@extends('layouts.erp')

@php
    $rp = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    // ROAS: < 10 satu desimal, ≥ 10 bulat; tanpa belanja = kosong.
    $fRoas = fn ($r) => $r === null ? '' : ($r < 10 ? number_format($r, 1, ',', '.') : number_format($r, 0, ',', '.'));
    $rataRoas = $kinerja['rata'] ?? null;
    // Warna relatif ROAS rata-rata kelompok: < ½ merah, > 1,2× hijau.
    $warnaRoas = function ($r) use ($rataRoas) {
        if ($r === null || !$rataRoas) return 'text-gray-400';
        if ($r < $rataRoas * 0.5) return 'text-red-600';
        if ($r > $rataRoas * 1.2) return 'text-green-700';
        return 'text-amber-700';
    };
    $judulSel = fn ($c, $label) => $c ? $label . ': belanja ' . $rp($c['biaya']) . ' · ' . $c['klik'] . ' klik · ' . $c['pesanan'] . ' pesanan · omzet ' . $rp($c['gmv']) . ' · ROAS ' . ($c['roas'] === null ? '—' : number_format($c['roas'], 1, ',', '.')) : $label . ': belum ada belanja';
    $jamRamai = old('jam_ramai', $k->jamRamai());
    $jamRamai = array_map('intval', is_array($jamRamai) ? $jamRamai : []);
    $idAnggota = array_map('intval', old('anggota', $k->exists ? $k->iklan()->pluck('campaign_id')->all() : []));
    $infoAnggota = $anggota->keyBy(fn ($a) => $a['iklan']->campaign_id);
@endphp

@section('content')
<div class="flex items-center gap-2 mb-1 flex-wrap">
    <a href="{{ route('sales.iklan-shopee.kelompok.index') }}" class="text-sm text-blue-700">← Kelompok</a>
    <h1 class="text-lg font-semibold">{{ $k->exists ? $k->nama : 'Kelompok iklan baru' }}</h1>
    @if ($k->exists)
        <span class="text-xs rounded px-1.5 py-0.5 {{ $k->mode === 'bayangan' ? 'bg-amber-100 text-amber-800' : ($k->mode === 'otomatis' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500') }}">{{ $k->labelMode() }}</span>
    @endif
</div>

@if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 rounded p-2 text-sm mb-3">{{ implode(' ', $errors->all()) }}</div>
@endif

<form method="POST" action="{{ $k->exists ? route('sales.iklan-shopee.kelompok.update', $k) : route('sales.iklan-shopee.kelompok.store') }}">
    @csrf
    @if ($k->exists) @method('PUT') @endif

    <div class="grid lg:grid-cols-2 gap-3 mb-3">
        {{-- Pengaturan --}}
        <div class="bg-white rounded shadow p-3 text-sm space-y-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Nama kelompok</label>
                <input name="nama" value="{{ old('nama', $k->nama) }}" required maxlength="100" placeholder="mis. Tempat Brosur" class="border rounded px-2 py-1.5 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Mode</label>
                <select name="mode" class="border rounded px-2 py-1.5 w-full">
                    <option value="mati" @selected(old('mode', $k->mode) === 'mati')>Mati — jadwal tidak dijalankan</option>
                    <option value="bayangan" @selected(old('mode', $k->mode) === 'bayangan')>Bayangan — hanya mencatat, iklan tidak diubah</option>
                    <option value="otomatis" @selected(old('mode', $k->mode) === 'otomatis')>Otomatis — menjeda &amp; melanjutkan iklan di Shopee</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Otomatis: tiap awal jam status asli dibaca dari Shopee lalu iklan anggota dijeda/dilanjutkan sesuai rencana di bawah. Iklan anggota yang dijeda/dilanjutkan manual di Seller Centre akan dikembalikan ke rencana pada jam berikutnya — untuk berhenti, pilih Mati atau keluarkan iklannya dari kelompok. Saat diganti ke Mati, iklan dibiarkan pada status terakhirnya.</p>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Aktif di jam sepi</label>
                    <input type="number" name="aktif_sepi" min="1" max="20" value="{{ old('aktif_sepi', $k->aktif_sepi) }}" class="border rounded px-2 py-1.5 w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Ganti giliran tiap</label>
                    <select name="rotasi_jam" class="border rounded px-2 py-1.5 w-full">
                        @foreach (\App\Modules\Marketplace\Shopee\Controllers\IklanKelompokController::ROTASI as $r)
                            <option value="{{ $r }}" @selected((int) old('rotasi_jam', $k->rotasi_jam) === $r)>{{ $r }} jam</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Batas belanja/hari</label>
                    <input name="batas_belanja" value="{{ old('batas_belanja', $k->batas_belanja ? (int) $k->batas_belanja : '') }}" placeholder="kosong = tanpa" class="rupiah-input border rounded px-2 py-1.5 w-full">
                </div>
            </div>
            <p class="text-xs text-gray-500">Batas belanja: bila belanja anggota hari ini sudah mencapai angka ini, sisa hari diperlakukan seperti jam sepi.
                @if ($k->exists) Belanja kelompok hari ini: <b>{{ $rp($belanja) }}</b>.@endif</p>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-xs text-gray-500">Jam ramai — semua anggota tayang</label>
                    <span class="text-xs">
                        <button type="button" class="text-blue-700" data-saran="{{ implode(',', $saranJam) }}" id="pakai-saran">Pakai saran data</button>
                        · <button type="button" class="text-gray-500" id="kosongkan-jam">kosongkan</button>
                    </span>
                </div>
                <div class="grid grid-cols-8 sm:grid-cols-12 gap-1" id="pilih-jam">
                    @for ($j = 0; $j < 24; $j++)
                        <label class="border rounded text-center text-xs py-1 cursor-pointer has-[:checked]:bg-green-100 has-[:checked]:border-green-400">
                            <input type="checkbox" name="jam_ramai[]" value="{{ $j }}" class="sr-only" @checked(in_array($j, $jamRamai, true))>{{ sprintf('%02d', $j) }}
                        </label>
                    @endfor
                </div>
                <p class="text-xs text-gray-500 mt-1">Saran dari data 14 hari (jam dengan ROAS ≥ rata-rata{{ $k->exists && $anggota->isNotEmpty() ? ' anggota' : ' semua iklan' }}):
                    {{ $saranJam ? implode(', ', array_map(fn ($j) => sprintf('%02d', $j), $saranJam)) : '—' }}</p>
            </div>
        </div>

        {{-- Anggota --}}
        <div class="bg-white rounded shadow text-sm flex flex-col">
            <div class="p-3 border-b flex items-center gap-2 flex-wrap">
                <span class="font-semibold">Anggota</span>
                <span class="text-xs text-gray-500" id="jumlah-anggota"></span>
                <input type="text" id="saring-iklan" placeholder="saring nama, mis. brosur" class="border rounded px-2 py-1 text-xs ml-auto w-48">
                <button type="button" class="text-xs text-blue-700" id="centang-tampil">centang yang tampil</button>
            </div>
            <div class="overflow-y-auto max-h-96">
                @forelse ($calon as $i)
                    @php $info = $infoAnggota[$i->campaign_id] ?? null; @endphp
                    <label class="flex items-start gap-2 px-3 py-1.5 border-b border-gray-50 hover:bg-gray-50 cursor-pointer baris-iklan" data-nama="{{ mb_strtolower(($sku[$i->campaign_id] ?? '') . ' ' . $i->nama) }}">
                        <input type="checkbox" name="anggota[]" value="{{ $i->campaign_id }}" class="mt-0.5" @checked(in_array($i->campaign_id, $idAnggota, true))>
                        <span class="flex-1 min-w-0">
                            <span class="block truncate text-xs font-medium" title="{{ $i->nama }}">{{ $sku[$i->campaign_id] ?? $i->nama }}</span>
                            <span class="text-[10px] text-gray-400">#{{ $i->campaign_id }} · {{ $i->labelStatus() }} · modal {{ (float) $i->modal_harian > 0 ? $rp($i->modal_harian) : 'tanpa batas' }}
                                @if ($info) · ROAS {{ $info['roas'] !== null ? number_format($info['roas'], 1, ',', '.') : '—' }} · bobot {{ number_format($info['bobot'], 1, ',', '.') }}{{ $info['stok'] !== null ? ' · stok ' . $info['stok'] : '' }}@endif
                            </span>
                            @if ($info && $info['alasan'])<span class="text-[10px] text-red-600">tidak diberi giliran: {{ $info['alasan'] }}</span>@endif
                        </span>
                    </label>
                @empty
                    <p class="p-3 text-xs text-gray-400">Tidak ada iklan berjalan/dijeda yang belum masuk kelompok lain.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="flex items-center gap-2 mb-4">
        <button class="bg-blue-600 text-white px-4 py-1.5 rounded text-sm">Simpan</button>
        @if ($k->exists)<span class="text-xs text-gray-500">Rencana di bawah dihitung ulang setelah disimpan.</span>@endif
    </div>
</form>

@if ($k->exists)
    {{-- Rencana hari ini --}}
    <div class="bg-white rounded shadow p-3 mb-3">
        <div class="flex items-center justify-between gap-2 flex-wrap mb-2 text-sm">
            <div class="font-semibold flex items-center gap-2 flex-wrap">Rencana tayang hari ini
                <form method="GET" class="inline font-normal text-xs text-gray-500">angka = ROAS
                    <select name="roas" onchange="this.form.submit()" class="border rounded px-1 py-0.5 text-xs">
                        @foreach (\App\Modules\Marketplace\Shopee\Controllers\IklanKelompokController::HARI_ROAS as $h)
                            <option value="{{ $h }}" @selected($hariRoas === $h)>{{ $h }} hari</option>
                        @endforeach
                    </select>
                    terakhir (rata-rata kelompok {{ $rataRoas === null ? '—' : number_format($rataRoas, 1, ',', '.') }})
                </form>
            </div>
            <div class="text-xs {{ $aksiPerHari > $maksAksi ? 'text-red-600 font-semibold' : 'text-gray-500' }}">± {{ $aksiPerHari }} aksi pause/lanjut per hari{{ $aksiPerHari > $maksAksi ? ' (melebihi batas ' . $maksAksi . ')' : '' }} · hijau = tayang · hijau muda = dijadwalkan, menunggu stok · ▲ = jam ramai · ✎ = jadwal manual</div>
        </div>
        @if ($pecahanRamai > 2 && $anggota->count() > $k->aktif_sepi)
            <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded p-2 text-xs mb-2">
                Jam ramai terpecah jadi {{ $pecahanRamai }} bagian ({{ $k->ringkasJamRamai() }}). Tiap peralihan ramai↔sepi menjeda/melanjutkan banyak iklan sekaligus —
                menyatukannya (mis. satu rentang pagi–malam) bisa memangkas aksi/hari dengan hasil hampir sama.
            </div>
        @endif
        @if ($anggota->isEmpty())
            <p class="text-xs text-gray-400">Belum ada anggota.</p>
        @else
            <p class="text-xs text-gray-500 mb-2">Klik kotak (atau tahan &amp; seret) untuk mengatur sendiri jam tayang satu iklan. Iklan yang diatur sendiri tayang persis di jam pilihan dan tidak ikut giliran; iklan lain tetap bergiliran otomatis. Iklan yang stoknya habis juga bisa disiapkan jadwalnya: begitu stok ada lagi, iklan langsung tayang sesuai jadwal itu tanpa perlu diatur ulang.
                <b>Modal ERP</b>: belanja per hari yang dijaga ERP — begitu tercapai, iklan dijeda sampai besok (modal di Shopee hanya batas atas; Shopee menaikkannya ke modal minimal saat iklan dilanjutkan). Data belanja Shopee tertinggal ±1 jam, jadi bisa sedikit lewat.</p>
            <form method="POST" action="{{ route('sales.iklan-shopee.kelompok.jadwal-manual', $k) }}" id="form-manual">
                @csrf @method('PUT')
                <div class="overflow-x-auto select-none">
                    <table class="text-[10px] border-collapse min-w-[820px] w-full">
                        <thead><tr>
                            <th class="text-left font-normal text-gray-500 pr-2">Iklan</th>
                            @for ($j = 0; $j < 24; $j++)
                                <th class="font-normal w-6 {{ $j === (int) now()->format('G') ? 'text-blue-700 font-semibold' : 'text-gray-500' }}">{{ sprintf('%02d', $j) }}{!! in_array($j, $k->jamRamai(), true) ? '<br>▲' : '<br>&nbsp;' !!}</th>
                            @endfor
                            <th class="font-normal text-gray-500 text-right pl-2" title="ROAS iklan ini dalam rentang terpilih">ROAS</th>
                            <th class="font-normal text-gray-500 text-right pl-2" title="Belanja hari ini / modal ERP per hari. Habis → dijeda sampai besok.">Hari ini / modal ERP</th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                        @foreach ($anggota as $a)
                            @php
                                $cid = $a['iklan']->campaign_id;
                                $manual = $a['manual'];
                                $tayang = fn ($j) => $manual !== null ? in_array($j, $manual, true) : in_array($cid, $rencana[$j], true);
                            @endphp
                            <tr class="baris-grid" data-cid="{{ $cid }}" data-layak="{{ $a['layak'] ? 1 : 0 }}">
                                <td class="pr-2 py-0.5 max-w-[16rem]">
                                    <div class="truncate {{ $a['layak'] ? '' : 'text-gray-400' }}" title="{{ $a['iklan']->nama }}{{ isset($sku[$cid]) ? ' — ' . $sku[$cid] : '' }}">
                                        @if ($manual !== null)<span class="text-blue-700 no-underline" title="Jadwal manual">✎</span>@endif
                                        {{ $sku[$cid] ?? $a['iklan']->nama }}
                                    </div>
                                    @if (!$a['layak'])
                                        <div class="text-[10px] text-red-600">sementara tidak tayang: {{ $a['alasan'] }}
                                            <span class="text-gray-500">— {{ $manual !== null ? 'jadwal hijau muda dipakai lagi saat bisa tayang' : 'klik kotak untuk menyiapkan jadwalnya; tanpa itu ikut giliran otomatis' }}</span>
                                        </div>
                                    @endif
                                </td>
                                @for ($j = 0; $j < 24; $j++)
                                    @php $c = $kinerja['iklan'][$cid][$j] ?? null; $on = $tayang($j); @endphp
                                    <td class="p-0.5">
                                        <div class="sel h-5 rounded-sm cursor-pointer text-[9px] leading-5 text-center font-medium {{ $on ? ($a['layak'] ? 'bg-green-500 text-white' : 'bg-green-200 text-green-900') : 'bg-gray-100 hover:bg-gray-200 text-gray-500' }} {{ $j === (int) now()->format('G') ? 'ring-1 ring-blue-500' : '' }}"
                                             data-jam="{{ $j }}" data-on="{{ $on ? 1 : 0 }}" title="{{ $judulSel($c, sprintf('Jam %02d, %d hari', $j, $hariRoas)) }}">{{ $c ? $fRoas($c['roas']) : '' }}</div>
                                    </td>
                                @endfor
                                @php $t = $kinerja['total_iklan'][$cid] ?? null; @endphp
                                <td class="pl-2 text-right whitespace-nowrap font-semibold {{ $warnaRoas($t['roas'] ?? null) }}" title="{{ $judulSel($t, $hariRoas . ' hari') }}">{{ $t && $t['roas'] !== null ? number_format($t['roas'], 1, ',', '.') : '—' }}</td>
                                <td class="pl-2 whitespace-nowrap text-right">
                                    <span class="{{ $a['modal_erp'] !== null && $a['hari_ini'] >= $a['modal_erp'] ? 'text-red-600 font-semibold' : 'text-gray-500' }}">{{ number_format($a['hari_ini'] / 1000, 1, ',', '.') }}rb /</span>
                                    <input name="modal_erp[{{ $cid }}]" value="{{ $a['modal_erp'] !== null ? (int) $a['modal_erp'] : '' }}" placeholder="—"
                                           class="rupiah-input modal-erp border rounded px-1 py-0.5 w-16 text-right text-[10px]" title="Modal ERP per hari (Shopee: Rp{{ number_format((float) $a['iklan']->modal_harian, 0, ',', '.') }})">
                                </td>
                                <td class="pl-1 whitespace-nowrap">
                                    @if ($manual !== null)
                                        <button name="otomatis" value="{{ $cid }}" class="text-[10px] text-blue-700" title="Kembalikan ke giliran otomatis">↺ otomatis</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <tr class="text-gray-500">
                            <td class="pr-2 pt-1 font-semibold text-gray-700">ROAS kelompok</td>
                            @for ($j = 0; $j < 24; $j++)
                                @php $c = $kinerja['jam'][$j] ?? null; @endphp
                                <td class="text-center pt-1 font-semibold {{ $warnaRoas($c['roas'] ?? null) }}" title="{{ $judulSel($c, sprintf('Semua anggota jam %02d, %d hari', $j, $hariRoas)) }}">{{ $c ? $fRoas($c['roas']) : '' }}</td>
                            @endfor
                            <td class="text-right pl-2 pt-1 font-semibold">{{ $rataRoas === null ? '—' : number_format($rataRoas, 1, ',', '.') }}</td>
                            <td></td><td></td>
                        </tr>
                        <tr class="text-gray-500">
                            <td class="pr-2 pt-1">Belanja/hari</td>
                            @for ($j = 0; $j < 24; $j++)
                                @php $c = $kinerja['jam'][$j] ?? null; @endphp
                                <td class="text-center pt-1 text-[9px]">{{ $c ? number_format($c['biaya'] / $hariRoas / 1000, 1, ',', '.') : '' }}</td>
                            @endfor
                            <td class="text-right pl-2 pt-1 text-[9px]">rb</td>
                            <td></td><td></td>
                        </tr>
                        <tr class="text-gray-500">
                            <td class="pr-2 pt-1">Jumlah tayang</td>
                            @for ($j = 0; $j < 24; $j++)<td class="text-center pt-1">{{ count($rencana[$j]) }}</td>@endfor
                            <td></td>
                            <td class="text-right pl-2 pt-1">{{ number_format($anggota->sum('hari_ini') / 1000, 1, ',', '.') }}rb / {{ number_format($anggota->sum(fn ($a) => $a['modal_erp'] ?? 0) / 1000, 0, ',', '.') }}rb</td>
                            <td></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div id="bar-manual" class="hidden mt-2 flex items-center gap-2 text-xs">
                    <button class="bg-blue-600 text-white px-3 py-1.5 rounded">Simpan jadwal &amp; modal ERP</button>
                    <a href="{{ url()->current() }}" class="text-gray-500">batal</a>
                    <span class="text-gray-500" id="info-manual"></span>
                </div>
            </form>
        @endif
    </div>

    <div class="bg-white rounded shadow mb-3">
        <div class="px-3 py-2 border-b font-semibold text-sm">Catatan aksi kelompok ini</div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-600"><tr>
                    <th class="text-left px-3 py-1.5">Waktu</th><th class="text-left px-2">Iklan</th><th class="text-left px-2">Aksi</th>
                    <th class="text-left px-2">Hasil</th><th class="text-left px-3">Alasan</th>
                </tr></thead>
                <tbody>
                @forelse ($log as $l)
                    @include('erp.sales.iklan-shopee.kelompok._log_baris', ['l' => $l, 'nama' => $sku[$l->campaign_id] ?? ($namaIklan[$l->campaign_id] ?? null)])
                @empty
                    <tr><td colspan="5" class="px-3 py-4 text-center text-gray-400">Belum ada catatan — jadwal berjalan tiap awal jam.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" action="{{ route('sales.iklan-shopee.kelompok.destroy', $k) }}" onsubmit="return confirm('Hapus kelompok ini? Iklan anggotanya tidak diubah.')">
        @csrf @method('DELETE')
        <button class="text-xs text-red-600">Hapus kelompok</button>
    </form>
@endif

<script>
(function () {
    var jamBox = document.querySelectorAll('#pilih-jam input');
    var saran = document.getElementById('pakai-saran');
    if (saran) {
        saran.addEventListener('click', function () {
            var daftar = (saran.dataset.saran || '').split(',').filter(Boolean);
            jamBox.forEach(function (c) { c.checked = daftar.indexOf(c.value) !== -1; });
        });
    }
    var kosong = document.getElementById('kosongkan-jam');
    if (kosong) {
        kosong.addEventListener('click', function () { jamBox.forEach(function (c) { c.checked = false; }); });
    }

    var baris = document.querySelectorAll('.baris-iklan');
    var info = document.getElementById('jumlah-anggota');
    function hitung() {
        var n = document.querySelectorAll('.baris-iklan input:checked').length;
        if (info) info.textContent = n + ' dipilih';
    }
    var saring = document.getElementById('saring-iklan');
    if (saring) {
        saring.addEventListener('input', function () {
            var q = saring.value.trim().toLowerCase();
            baris.forEach(function (b) { b.style.display = !q || b.dataset.nama.indexOf(q) !== -1 ? '' : 'none'; });
        });
    }
    var centang = document.getElementById('centang-tampil');
    if (centang) {
        centang.addEventListener('click', function () {
            baris.forEach(function (b) { if (b.style.display !== 'none') b.querySelector('input').checked = true; });
            hitung();
        });
    }
    baris.forEach(function (b) { b.querySelector('input').addEventListener('change', hitung); });
    hitung();

    // Grid jadwal manual: klik / tahan & seret untuk mengubah jam tayang satu iklan.
    var formManual = document.getElementById('form-manual');
    if (formManual) {
        var cat = null;
        var berubah = {};
        var bar = document.getElementById('bar-manual');
        var infoManual = document.getElementById('info-manual');

        function setel(sel, on) {
            sel.dataset.on = on ? '1' : '0';
            var tr = sel.closest('tr');
            var layak = tr.dataset.layak === '1';
            // Baris yang sementara tak bisa tayang (mis. stok habis): jadwalnya hijau muda.
            sel.classList.toggle('bg-green-500', on && layak);
            sel.classList.toggle('bg-green-200', on && !layak);
            sel.classList.toggle('bg-gray-100', !on);
            sel.classList.toggle('text-white', on && layak);
            sel.classList.toggle('text-green-900', on && !layak);
            sel.classList.toggle('text-gray-500', !on);
            var cid = tr.dataset.cid;
            berubah[cid] = true;
            var jam = [];
            tr.querySelectorAll('.sel').forEach(function (s) { if (s.dataset.on === '1') jam.push(s.dataset.jam); });
            var input = formManual.querySelector('input[name="baris[' + cid + ']"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'baris[' + cid + ']';
                formManual.appendChild(input);
            }
            input.value = jam.join(',');
            bar.classList.remove('hidden');
            infoManual.textContent = Object.keys(berubah).length + ' iklan diubah — belum disimpan';
        }

        formManual.querySelectorAll('.sel').forEach(function (sel) {
            sel.addEventListener('pointerdown', function (e) {
                e.preventDefault();
                cat = sel.dataset.on !== '1';
                setel(sel, cat);
            });
            sel.addEventListener('pointerenter', function () {
                if (cat !== null) setel(sel, cat);
            });
        });
        document.addEventListener('pointerup', function () { cat = null; });
        formManual.querySelectorAll('.modal-erp').forEach(function (inp) {
            inp.addEventListener('input', function () {
                bar.classList.remove('hidden');
                infoManual.textContent = 'modal ERP diubah — belum disimpan';
            });
        });
    }
})();
</script>
@endsection
