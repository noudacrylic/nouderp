@extends('layouts.erp')

@section('content')
<div class="w-full max-w-5xl px-6 py-4 space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-800">🧩 Potong Susulan</h1>
            <p class="text-xs text-gray-500">Komponen kurang / rusak yang dilaporkan Assembling saat Pending. Setelah dipotong, tekan <b>Selesai dipotong</b> dan pilih sumber bahannya.</p>
        </div>
        <a href="{{ route('production.process.index', array_filter(['department_id' => $departmentId])) }}" class="text-sm text-gray-600 hover:underline">← Proses Produksi</a>
    </div>

    @forelse($menunggu as $lap)
        <div class="bg-white rounded-2xl border-2 border-red-200 shadow-sm p-4" x-data="{ buka: false, sumber: '' }">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="text-lg font-black text-gray-800">
                        <a href="{{ route('production.orders.show', $lap->production_order_id) }}" class="hover:underline">{{ $lap->productionOrder->order_number ?? '—' }}</a>
                    </div>
                    <div class="text-xs text-gray-500">
                        {{ $lap->labelPenyebab() }} · dilaporkan {{ $lap->pelapor->name ?? '—' }} · {{ $lap->created_at->translatedFormat('D d/m H:i') }}
                        ({{ $lap->created_at->diffForHumans() }})
                    </div>
                </div>
                <button type="button" @click="buka = !buka" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl">✓ Selesai dipotong</button>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-3">
                @foreach($lap->items as $it)
                    <div class="border border-gray-200 rounded-xl p-2 flex gap-3 items-center">
                        @if($it->fotoUrl())
                            <a href="{{ $it->fotoUrl() }}" target="_blank"><img src="{{ $it->fotoUrl() }}" class="h-20 w-24 object-cover rounded-lg border border-gray-200"></a>
                        @else
                            <div class="h-20 w-24 rounded-lg bg-gray-50 border border-dashed border-gray-200 flex items-center justify-center text-[10px] text-gray-400">tanpa foto</div>
                        @endif
                        <div>
                            <div class="font-bold text-gray-800">{{ $it->nama }}</div>
                            <div class="text-2xl font-black text-red-600">×{{ $it->jumlah }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            <form x-show="buka" style="display:none" method="POST" class="mt-3 p-3 rounded-xl bg-gray-50 border border-gray-200"
                  action="{{ route('production.process.susulan.selesai', ['id' => $lap->id, 'department_id' => $departmentId]) }}">
                @csrf
                <div class="text-xs font-bold text-gray-600 mb-2">Bahan diambil dari mana?</div>
                <div class="flex flex-wrap gap-2">
                    <label class="flex items-center gap-2 px-3 py-2 border rounded-xl cursor-pointer text-sm" :class="sumber === 'stok' ? 'border-emerald-400 bg-emerald-50 font-semibold' : 'border-gray-200 bg-white'">
                        <input type="radio" name="sumber" value="stok" x-model="sumber"> Dari stok <span class="text-xs text-gray-500">(lanjut isi Penambahan Bahan)</span>
                    </label>
                    <label class="flex items-center gap-2 px-3 py-2 border rounded-xl cursor-pointer text-sm" :class="sumber === 'sisa' ? 'border-emerald-400 bg-emerald-50 font-semibold' : 'border-gray-200 bg-white'">
                        <input type="radio" name="sumber" value="sisa" x-model="sumber"> Bahan sisa / bekas <span class="text-xs text-gray-500">(tanpa penambahan bahan)</span>
                    </label>
                </div>
                <button type="submit" :disabled="!sumber" class="mt-3 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl disabled:opacity-50">Simpan</button>
            </form>
        </div>
    @empty
        <div class="bg-white rounded-2xl border border-gray-100 p-6 text-center text-sm text-gray-500">Tidak ada permintaan potong susulan. 👍</div>
    @endforelse

    @if($riwayat->isNotEmpty())
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <h2 class="font-bold text-gray-800 text-sm">Riwayat terakhir</h2>
            <table class="w-full text-xs mt-2">
                <thead class="text-gray-400"><tr>
                    <th class="text-left font-normal py-1">OP</th><th class="text-left font-normal">Penyebab</th><th class="text-left font-normal">Komponen</th>
                    <th class="text-left font-normal">Dilaporkan</th><th class="text-left font-normal">Selesai</th><th class="text-left font-normal">Bahan</th>
                </tr></thead>
                <tbody>
                @foreach($riwayat as $lap)
                    <tr class="border-t border-gray-100">
                        <td class="py-1"><a href="{{ route('production.orders.show', $lap->production_order_id) }}" class="text-blue-700 hover:underline">{{ $lap->productionOrder->order_number ?? '—' }}</a></td>
                        <td>{{ $lap->labelPenyebab() }}</td>
                        <td>{{ $lap->ringkasItem() }}</td>
                        <td class="whitespace-nowrap">{{ $lap->pelapor->name ?? '—' }} · {{ $lap->created_at->format('d/m H:i') }}</td>
                        <td class="whitespace-nowrap">{{ $lap->susulan_selesai_at?->format('d/m H:i') ?? ['tanpa_susulan' => 'tanpa susulan', 'dibatalkan' => 'dibatalkan Assembling (komponen sudah ada)'][$lap->status] ?? '—' }}</td>
                        <td>{{ ['stok' => 'stok', 'sisa' => 'sisa'][$lap->sumber_bahan] ?? '—' }}{{ $lap->material_addition_id ? ' · tercatat' : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
