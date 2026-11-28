{{-- Kartu Potong Susulan di panel Antre CNC (skor 300) — $lap: KomponenLaporan --}}
<div class="border-2 border-red-300 bg-red-50/60 rounded-xl p-2.5" x-data="{ buka: false, sumber: '' }">
    <div class="flex items-start justify-between gap-2">
        <div>
            <div class="text-[10px] font-black text-red-700 tracking-wide">🧩 POTONG SUSULAN · skor {{ (int) \App\Modules\Production\Controllers\ProductionProcessController::SKOR_SUSULAN }}</div>
            <a href="{{ route('production.orders.show', $lap->production_order_id) }}" class="text-sm font-black text-gray-800 hover:underline">{{ $lap->productionOrder->order_number ?? '—' }}</a>
            <div class="text-[11px] text-gray-500">{{ $lap->labelPenyebab() }} · {{ $lap->pelapor->name ?? '—' }} · {{ $lap->created_at->diffForHumans() }}</div>
        </div>
        <button type="button" @click="buka = !buka" class="shrink-0 px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-lg">✓ Selesai dipotong</button>
    </div>
    <div class="flex flex-wrap gap-2 mt-2">
        @foreach($lap->items as $it)
            <div class="flex items-center gap-1.5 bg-white border border-gray-200 rounded-lg px-1.5 py-1">
                @if($it->fotoUrl())
                    <a href="{{ $it->fotoUrl() }}" target="_blank"><img src="{{ $it->fotoUrl() }}" class="h-9 w-11 object-cover rounded"></a>
                @endif
                <span class="text-xs font-semibold text-gray-700">{{ $it->nama }}</span>
                <span class="text-sm font-black text-red-600">×{{ $it->jumlah }}</span>
            </div>
        @endforeach
    </div>
    <form x-show="buka" style="display:none" method="POST" class="mt-2 p-2 rounded-lg bg-white border border-gray-200"
          action="{{ route('production.process.susulan.selesai', ['id' => $lap->id, 'department_id' => $departmentId ?? null]) }}">
        @csrf
        <div class="text-[11px] font-bold text-gray-600 mb-1">Bahan diambil dari mana?</div>
        <label class="flex items-center gap-1.5 text-xs py-0.5"><input type="radio" name="sumber" value="stok" x-model="sumber"> Dari stok <span class="text-gray-400">(lanjut Penambahan Bahan)</span></label>
        <label class="flex items-center gap-1.5 text-xs py-0.5"><input type="radio" name="sumber" value="sisa" x-model="sumber"> Bahan sisa / bekas</label>
        <button type="submit" :disabled="!sumber" class="mt-1.5 px-3 py-1 bg-emerald-600 text-white text-xs font-bold rounded-lg disabled:opacity-50">Simpan</button>
    </form>
</div>
