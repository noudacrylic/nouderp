@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    <div class="max-w-xl mx-auto flex justify-between items-center mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Pengaturan Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">Konfigurasi kalkulasi Score BOM dan parameter produksi.</p>
        </div>
        <a href="{{ route('production.boms.index') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← BOM</a>
    </div>



    @php
        $curChoice = match($setting->score_period_mode ?? 'months') {
            'week'  => 'week',
            'range' => 'range',
            default => (string) max(1, (int) ($setting->score_sales_period ?? 1)),
        };
        if (!in_array($curChoice, ['week','1','2','3','range'], true)) $curChoice = '1';
    @endphp

    <div class="max-w-xl mx-auto">
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6"
             x-data="{ choice: '{{ $curChoice }}' }">
            <h3 class="font-bold text-gray-700 mb-1 text-sm">Periode Penjualan untuk Kalkulasi Score</h3>
            <p class="text-xs text-gray-400 mb-5">
                Score BOM = Demand + Bobot Stok. Demand dihitung dari total penjualan pada periode terpilih
                dibagi kapasitas produksi (Jumlah Siklus × Output Utama per Siklus).
            </p>

            <form action="{{ route('production.settings.update') }}" method="POST">
                @csrf

                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-500 mb-1">Periode penjualan</label>
                    <select name="period_choice" x-model="choice"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-300">
                        <option value="week">Mingguan (7 hari terakhir)</option>
                        <option value="1">1 Bulan Terakhir</option>
                        <option value="2">2 Bulan Terakhir</option>
                        <option value="3">3 Bulan Terakhir</option>
                        <option value="range">Rentang Tanggal (tetap)</option>
                    </select>
                </div>

                {{-- Input rentang — muncul saat pilih "Rentang Tanggal" --}}
                <div x-show="choice === 'range'" x-transition style="display:none" class="mb-4 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Dari Tanggal</label>
                        <input type="date" name="period_start"
                               value="{{ optional($setting->score_period_start)->format('Y-m-d') }}"
                               class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Sampai Tanggal</label>
                        <input type="date" name="period_end"
                               value="{{ optional($setting->score_period_end)->format('Y-m-d') }}"
                               class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-3 mb-4 text-xs text-blue-700">
                    <span x-show="choice === 'week'">📅 <b>Mingguan</b>: penjualan 7 hari terakhir (bergulir). Data awal penjualan ikut diproporsikan per jumlah hari.</span>
                    <span x-show="choice !== 'week' && choice !== 'range'">📅 <b>Bulanan</b>: penjualan N bulan terakhir penuh (bergulir, selalu mengikuti bulan berjalan).</span>
                    <span x-show="choice === 'range'">📅 <b>Rentang tetap</b>: periode membeku pada tanggal yang dipilih — tidak bergulir otomatis seiring waktu.</span>
                </div>

                <div class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 mb-5 text-xs text-amber-700">
                    <strong>Rumus Score:</strong><br>
                    Kapasitas = Jumlah Siklus × Qty Output Utama per Siklus<br>
                    Demand = Total Penjualan (SO + Data Awal) pada Periode / Kapasitas<br>
                    Bobot Stok: Minus +300 | Habis +200 | Menipis +100 | OK +0 | PreOrder +300<br>
                    <strong>Score = Demand + Bobot Stok</strong>
                </div>

                <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl text-sm font-bold transition">
                    Simpan Pengaturan
                </button>
            </form>
        </div>

        <div class="mt-4 bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
            <h3 class="font-bold text-gray-700 mb-3 text-sm">Aksi Cepat</h3>
            <div class="space-y-2">
                <form action="{{ route('production.boms.recalculate') }}" method="POST">
                    @csrf
                    <button type="submit"
                            class="w-full border border-gray-200 text-gray-600 hover:bg-gray-50 py-2.5 rounded-xl text-sm font-semibold transition text-left px-4">
                        ↻ Hitung Ulang Score Semua BOM Aktif
                    </button>
                </form>
                <a href="{{ route('sales.reports.manual-sales') }}"
                   class="block w-full border border-gray-200 text-gray-600 hover:bg-gray-50 py-2.5 rounded-xl text-sm font-semibold transition px-4">
                    ✏ Input Data Awal Penjualan →
                </a>
            </div>
        </div>
    </div>

</div>
@endsection
