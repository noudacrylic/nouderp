@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Pengaturan Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">Konfigurasi kalkulasi Score BOM dan parameter produksi.</p>
        </div>
        <a href="{{ route('production.boms.index') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← BOM</a>
    </div>


    <div class="max-w-xl">
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6">
            <h3 class="font-bold text-gray-700 mb-1 text-sm">Periode Penjualan untuk Kalkulasi Score</h3>
            <p class="text-xs text-gray-400 mb-5">
                Score BOM = Demand + Bobot Stok. Demand dihitung dari total penjualan N bulan terakhir
                dibagi kapasitas produksi (Jumlah Siklus × Output Utama per Siklus).
            </p>

            <form action="{{ route('production.settings.update') }}" method="POST">
                @csrf

                <div class="space-y-3 mb-6">
                    <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-50 transition"
                           :class="period == 1 ? 'border-blue-400 bg-blue-50' : ''">
                        <input type="radio" name="score_sales_period" value="1"
                               {{ $setting->score_sales_period == 1 ? 'checked' : '' }}
                               class="mt-0.5 accent-blue-600">
                        <div>
                            <div class="font-bold text-gray-700 text-sm">1 Bulan Terakhir</div>
                            <div class="text-xs text-gray-400 mt-0.5">
                                Data penjualan dari bulan ini saja. Lebih responsif terhadap perubahan permintaan terkini.
                            </div>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-50 transition">
                        <input type="radio" name="score_sales_period" value="3"
                               {{ $setting->score_sales_period == 3 ? 'checked' : '' }}
                               class="mt-0.5 accent-blue-600">
                        <div>
                            <div class="font-bold text-gray-700 text-sm">3 Bulan Terakhir</div>
                            <div class="text-xs text-gray-400 mt-0.5">
                                Rata-rata 3 bulan ke belakang. Lebih stabil, cocok untuk produk dengan pola permintaan konsisten.
                            </div>
                        </div>
                    </label>
                </div>

                <div class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 mb-5 text-xs text-amber-700">
                    <strong>Rumus Score:</strong><br>
                    Kapasitas = Jumlah Siklus × Qty Output Utama per Siklus<br>
                    Demand = Total Penjualan N Bulan / Kapasitas<br>
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
                    ✏ Input Data Penjualan Manual →
                </a>
            </div>
        </div>
    </div>

</div>
@endsection
