{{--
    Sub-tab tier-3 di dalam halaman Pemrosesan Pesanan (tier-1 & 2 dirender layout).
    Param: $items = [['label' =>, 'url' =>, 'active' => bool, 'count' => ?int, 'alert' => bool]]
--}}
<div class="mb-3 flex items-center gap-1.5 flex-wrap">
    @foreach($items as $it)
        <a href="{{ $it['url'] }}"
           class="px-3 py-1.5 rounded-full text-xs font-bold border transition inline-flex items-center gap-1.5
                  {{ ($it['active'] ?? false)
                       ? 'bg-indigo-600 border-indigo-600 text-white'
                       : 'bg-white border-gray-300 text-gray-600 hover:bg-gray-50' }}">
            {{ $it['label'] }}
            @if(($it['count'] ?? 0) > 0)
                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-black leading-none
                             {{ ($it['active'] ?? false)
                                  ? 'bg-white/25 text-white'
                                  : (($it['alert'] ?? false) ? 'bg-red-600 text-white' : 'bg-gray-200 text-gray-600') }}">
                    {{ $it['count'] }}
                </span>
            @endif
            {{-- Penanda terpisah: berapa di antaranya mendesak — kurir instant (driver sudah
                 dipesan) & ambil di toko (pembeli menunggu di depan). --}}
            @if(($it['instant'] ?? 0) > 0)
                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-black leading-none bg-orange-500 text-white"
                      title="{{ $it['instant'] }} pesanan instant menunggu">⚡{{ $it['instant'] }}</span>
            @endif
            @if(($it['pickup'] ?? 0) > 0)
                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-black leading-none bg-amber-500 text-white"
                      title="{{ $it['pickup'] }} pesanan ambil di toko menunggu">🏬{{ $it['pickup'] }}</span>
            @endif
        </a>
    @endforeach
</div>
