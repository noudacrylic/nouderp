@extends('layouts.erp')

@section('content')
@php
    $rp  = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $jam = fn ($detik) => number_format((float) $detik / 3600, 0, ',', '.');

    $nonProd  = $result['groups']['non_produksi'];
    $packing  = $result['groups']['packing'];
    $overhead = $result['groups']['overhead_produksi'];
    // Jam PABRIK buka — pembagi biaya tetap. Bukan jumlah jam seluruh divisi.
    $prodOper = $result['factory_operating_seconds'];

    // Semua komponen yang bisa diubah/dihapus, untuk merender form di luar tabel.
    $editable = collect($result['groups'])->pluck('components')->flatten(1)
        ->merge(collect($result['departments'])->pluck('components')->flatten(1))
        ->filter(fn ($l) => !empty($l['id']));
@endphp
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-start mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Fixed Cost</h1>
            <p class="text-sm text-slate-500 mt-0.5">
                Daftar biaya tetap per bulan, apa adanya. Pembaginya tidak lagi hidup di sini —
                kapasitas jam ada di <strong>Kuota Produksi</strong>, waktu per unit di <strong>Waktu Produksi</strong>,
                dan HPP yang merakit ketiganya.
            </p>
        </div>
        <a href="{{ route('analisa.hpp.index') }}"
           class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold transition">
            Lihat HPP Produk →
        </a>
    </div>

    {{-- Filter --}}
    <form method="GET" class="bg-white border border-slate-100 rounded-2xl shadow-sm p-4 mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Periode rata-rata</label>
            <select name="months" class="border border-slate-200 rounded-xl px-3 py-2 text-sm">
                @foreach([1 => '1 bulan terakhir', 2 => '2 bulan terakhir', 3 => '3 bulan terakhir', 6 => '6 bulan terakhir', 12 => '12 bulan terakhir'] as $m => $label)
                    <option value="{{ $m }}" @selected((int) request('months', 3) === $m)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Atau dari tanggal</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="border border-slate-200 rounded-xl px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Sampai</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="border border-slate-200 rounded-xl px-3 py-2 text-sm">
        </div>
        <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl transition">Hitung Ulang</button>
        <a href="{{ route('analisa.biaya-divisi.index') }}" class="px-4 py-2 border border-slate-200 text-slate-600 text-sm font-semibold rounded-xl hover:bg-slate-50 transition">Reset</a>
        <div class="text-sm text-slate-400 ml-auto">{{ $result['period']['from'] }} s/d {{ $result['period']['to'] }}</div>
    </form>

    @foreach($result['warnings'] as $w)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl px-4 py-2.5 mb-3 text-sm">{{ $w }}</div>
    @endforeach

    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden mb-6">
        <div class="px-5 py-3.5 border-b border-slate-100">
            <h2 class="text-sm font-bold text-slate-800">Susunan Biaya per Bulan</h2>
            <p class="text-xs text-slate-500 mt-0.5">
                Semua yang tercantum di sini dibebankan ke HPP. Tidak ingin dibebankan? Hapus barisnya.
                Baris <strong>gaji</strong> selalu ada dan mengikuti slip gaji.
            </p>
        </div>

        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-100">
                    <th class="py-2.5 pl-5 pr-3 text-left font-black text-slate-400 text-[10px] uppercase tracking-widest">Komponen</th>
                    <th class="py-2.5 px-3 text-left font-black text-slate-400 text-[10px] uppercase tracking-widest">Sumber Komponen</th>
                    <th class="py-2.5 px-3 text-right font-black text-slate-400 text-[10px] uppercase tracking-widest" style="width:170px">Rp / Bulan</th>
                    <th class="py-2.5 pl-3 pr-5" style="width:120px"></th>
                </tr>
            </thead>
            <tbody>

                {{-- ══ INDUK 1: NON PRODUKSI ══ --}}
                {{-- Kolom Pembagi sengaja kosong di baris induk: pembaginya sudah tercantum
                     di tiap komponen, mengulangnya di sini hanya menambah angka yang harus dibaca. --}}
                <tr class="border-t-2 border-blue-200">
                    <td class="py-3 pl-5 pr-3 bg-blue-100 border-l-4 border-blue-600 font-black text-blue-900 uppercase tracking-wider text-xs">Non Produksi</td>
                    <td class="py-3 px-3 bg-blue-100 text-xs text-blue-800">gaji divisi non-produksi + biaya kantor &amp; gedung</td>
                    <td class="py-3 px-3 text-right bg-blue-100 font-black text-blue-900 tabular-nums">{{ $rp($nonProd['total']) }}</td>
                    <td class="bg-blue-100"></td>
                </tr>
                @include('erp.analisa.biaya-divisi._component-rows', [
                    'components' => $nonProd['components'],
                    'formId'     => 'addcmp-non_produksi',
                    'indent'     => 46,
                    'accent'     => 'blue',
                ])

                {{-- ══ INDUK 2: PACKING ══ --}}
                {{-- Diukur per transaksi, bukan per jam: kerja packing mengikuti jumlah
                     paket yang keluar, bukan lamanya pabrik buka. --}}
                <tr class="border-t-2 border-teal-200">
                    <td class="py-3 pl-5 pr-3 bg-teal-100 border-l-4 border-teal-600 font-black text-teal-900 uppercase tracking-wider text-xs">Packing</td>
                    <td class="py-3 px-3 bg-teal-100 text-xs text-teal-800">biaya membungkus &amp; menyiapkan paket</td>
                    <td class="py-3 px-3 text-right bg-teal-100 font-black text-teal-900 tabular-nums">{{ $rp($packing['total']) }}</td>
                    <td class="bg-teal-100"></td>
                </tr>
                @include('erp.analisa.biaya-divisi._component-rows', [
                    'components' => $packing['components'],
                    'formId'     => 'addcmp-packing',
                    'indent'     => 46,
                    'accent'     => 'teal',
                ])

                {{-- ══ INDUK 3: PRODUKSI ══ --}}
                <tr class="border-t-2 border-indigo-200">
                    <td class="py-3 pl-5 pr-3 bg-indigo-100 border-l-4 border-indigo-600 font-black text-indigo-900 uppercase tracking-wider text-xs">Produksi</td>
                    <td class="py-3 px-3 bg-indigo-100 text-xs text-indigo-800">overhead pabrik + biaya tiap divisi produksi</td>
                    <td class="py-3 px-3 text-right bg-indigo-100 font-black text-indigo-900 tabular-nums">{{ $rp($result['produksi_total']) }}</td>
                    <td class="bg-indigo-100"></td>
                </tr>

                {{-- ── Anak 2a: Overhead Produksi ── --}}
                <tr>
                    <td class="py-2.5 pr-3 bg-indigo-50 border-l-4 border-indigo-300 font-bold text-indigo-900" style="padding-left:30px">Overhead Produksi</td>
                    <td class="py-2.5 px-3 bg-indigo-50 text-xs text-indigo-700">ditanggung bersama, dibagi ke divisi produksi</td>
                    <td class="py-2.5 px-3 text-right bg-indigo-50 font-bold text-indigo-900 tabular-nums">{{ $rp($overhead['total']) }}</td>
                    <td class="bg-indigo-50"></td>
                </tr>
                @include('erp.analisa.biaya-divisi._component-rows', [
                    'components' => $overhead['components'],
                    'formId'     => 'addcmp-overhead_produksi',
                    'indent'     => 62,
                    'accent'     => 'indigo',
                ])

                {{-- ── Anak 2b: Divisi Produksi ── --}}
                <tr>
                    <td class="py-2.5 pr-3 bg-indigo-50 border-l-4 border-indigo-300 font-bold text-indigo-900" style="padding-left:30px">Divisi Produksi</td>
                    <td class="py-2.5 px-3 bg-indigo-50 text-xs text-indigo-700">biaya yang melekat pada satu divisi</td>
                    <td class="py-2.5 px-3 text-right bg-indigo-50 font-bold text-indigo-900 tabular-nums">
                        {{ $rp(collect($result['departments'])->sum('direct_total')) }}
                    </td>
                    <td class="bg-indigo-50"></td>
                </tr>

                @forelse($result['departments'] as $row)
                    @php $dept = $row['department']; @endphp
                    <tr>
                        <td class="py-2.5 pr-3 bg-white font-semibold text-slate-800 border-l-4 border-slate-300" style="padding-left:58px">
                            {{ $dept['name'] }}
                        </td>
                        <td class="py-2.5 px-3 text-xs text-slate-500">biaya yang melekat pada divisi ini</td>
                        <td class="py-2.5 px-3 text-right font-bold text-slate-800 tabular-nums">{{ $rp($row['direct_total']) }}</td>
                        <td></td>
                    </tr>
                    @include('erp.analisa.biaya-divisi._component-rows', [
                        'components' => $row['components'],
                        'formId'     => 'addcmp-dept-' . $dept['id'],
                        'indent'     => 86,
                        'accent'     => 'slate',
                    ])
                @empty
                    <tr>
                        <td colspan="4" class="py-6 text-center text-sm text-slate-400">
                            Belum ada divisi bertipe produksi. Tambahkan di menu Produksi → Divisi.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-4 border-slate-800 bg-slate-800">
                    <td class="py-3.5 pl-5 pr-3 font-black text-white uppercase tracking-wider text-xs">Total Fixed Cost</td>
                    <td class="py-3.5 px-3 text-xs text-slate-300">seluruh biaya tetap yang dibebankan ke HPP</td>
                    <td class="py-3.5 px-3 text-right font-black text-white tabular-nums text-base">{{ $rp($result['grand_total']) }}</td>
                    <td class="bg-slate-800"></td>
                </tr>
            </tfoot>
        </table>
        </div>

        <div class="px-5 py-3 text-xs text-slate-500 border-t border-slate-100 space-y-1">
            <div>
                Semua yang tercantum di sini dibebankan ke HPP. Baris <strong>gaji</strong> selalu ada dan
                mengikuti slip gaji — tidak bisa diedit maupun dihapus dari sini.
            </div>
            <div>
                Angka gaji memakai <strong>hari kerja aktual</strong> periode ini dari slip gaji
                @if($result['working_days'] !== null)
                    ({{ rtrim(rtrim(number_format($result['working_days'], 1, ',', '.'), '0'), ',') }} hari), sudah dipotong tanggal merah.
                @else
                    — periode ini belum punya slip gaji, jadi dipakai perkiraan dari jadwal kontrak.
                @endif
            </div>
            <div>
                <strong>Pembagi tidak lagi ada di halaman ini.</strong> Kapasitas jam hidup di
                <a href="{{ route('analisa.kuota.index') }}" class="text-blue-600 font-semibold hover:underline">Kuota Produksi</a>,
                waktu per unit di
                <a href="{{ route('analisa.waktu-produksi.index') }}" class="text-blue-600 font-semibold hover:underline">Waktu Produksi</a>.
                Satu angka dihitung di satu tempat saja.
            </div>
        </div>
    </div>

    {{-- Form penampung: input-nya tersebar di dalam tabel lewat atribut form= --}}
    @foreach(App\Modules\Analysis\Models\ProductionCostComponent::POOL_GROUPS as $groupKey)
        <form id="addcmp-{{ $groupKey }}" method="POST" action="{{ route('analisa.biaya-divisi.component.store') }}" class="hidden">
            @csrf
            <input type="hidden" name="group_key" value="{{ $groupKey }}">
        </form>
    @endforeach
    @foreach($result['departments'] as $row)
        <form id="addcmp-dept-{{ $row['department']['id'] }}" method="POST" action="{{ route('analisa.biaya-divisi.component.store') }}" class="hidden">
            @csrf
            <input type="hidden" name="group_key" value="divisi">
            <input type="hidden" name="department_id" value="{{ $row['department']['id'] }}">
        </form>
    @endforeach
    @foreach($editable as $line)
        <form id="editcmp-{{ $line['id'] }}" method="POST" action="{{ route('analisa.biaya-divisi.component.update', $line['id']) }}" class="hidden">
            @csrf @method('PUT')
            <input type="hidden" name="group_key" value="{{ $line['group_key'] }}">
            <input type="hidden" name="department_id" value="{{ $line['department_id'] }}">
        </form>
        <form id="delcmp-{{ $line['id'] }}" method="POST" action="{{ route('analisa.biaya-divisi.component.destroy', $line['id']) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
    @endforeach

</div>

<script>
// Satu baris hanya boleh punya satu mode: lihat, ubah, atau tambah.
document.querySelectorAll('.btn-add-row').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var trigger = btn.closest('tr');
        var row     = trigger.previousElementSibling;      // .add-row
        row.classList.remove('hidden');
        trigger.classList.add('hidden');
        var input = row.querySelector('input[name="name"]');
        if (input) input.focus();
    });
});

document.querySelectorAll('.btn-cancel-row').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var row = btn.closest('tr');
        row.classList.add('hidden');
        if (row.nextElementSibling) row.nextElementSibling.classList.remove('hidden');
    });
});

document.querySelectorAll('.btn-edit-row').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var view = btn.closest('tr');
        view.classList.add('hidden');
        view.nextElementSibling.classList.remove('hidden');   // .cmp-edit
        var input = view.nextElementSibling.querySelector('input[name="name"]');
        if (input) input.focus();
    });
});

document.querySelectorAll('.btn-cancel-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var edit = btn.closest('tr');
        edit.classList.add('hidden');
        edit.previousElementSibling.classList.remove('hidden');
    });
});

// Sumber "manual" vs "dari akun" menampilkan field yang berbeda.
document.querySelectorAll('.komponen-source').forEach(function (sel) {
    sel.addEventListener('change', function () {
        var row    = sel.closest('tr');
        var isAkun = sel.value === 'akun';
        row.querySelectorAll('.komponen-akun').forEach(function (el) { el.classList.toggle('hidden', !isAkun); });
        row.querySelectorAll('.komponen-manual').forEach(function (el) { el.classList.toggle('hidden', isAkun); });
    });
});
</script>
@endsection
