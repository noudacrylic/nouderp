{{-- Filter bar pemrosesan pesanan. Param: $couriers (Collection nama kurir). --}}
<form method="GET" class="mb-3 flex items-center gap-2 flex-wrap">
    {{-- Sub-tab dibawa sebagai hidden: tanpa ini, menekan "Cari" akan melempar balik ke
         sub-tab pertama karena form GET hanya mengirim field yang ada di dalamnya. --}}
    @foreach(['tahap', 'resi', 'prioritas'] as $keep)
        @if(request($keep))
            <input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">
        @endif
    @endforeach

    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari nomor / pelanggan / produk / SKU…"
           class="border rounded px-3 py-2 text-sm w-72">

    <select name="channel" onchange="this.form.submit()" class="border rounded px-3 py-2 text-sm bg-white">
        <option value="">Semua Channel</option>
        <option value="marketplace" @selected(request('channel') === 'marketplace')>🛒 Marketplace</option>
        <option value="non" @selected(request('channel') === 'non')>🏬 Non-Marketplace</option>
    </select>

    <select name="courier" onchange="this.form.submit()" class="border rounded px-3 py-2 text-sm bg-white">
        <option value="">Semua Kurir</option>
        @foreach($couriers ?? [] as $c)
            <option value="{{ $c }}" @selected(request('courier') === $c)>{{ $c }}</option>
        @endforeach
    </select>

    {{-- Chip cepat "prioritas": kurir instant ATAU ambil di toko — dua-duanya ada orang yang
         menunggu di tempat. Angkanya ditempel di chip supaya operator tahu ADA berapa & jenis
         apa tanpa membuka daftarnya. Toggle lewat tautan supaya filter lain tetap terbawa.
         Chip hanya muncul di tab yang memang menerima filter ini ($prioritas dikirim controller). --}}
    @isset($prioritas)
        @php $prioritasOn = (bool) request('prioritas'); @endphp
        <a href="{{ request()->fullUrlWithQuery(['prioritas' => $prioritasOn ? null : 1]) }}"
           title="{{ $prioritas['total'] ? "{$prioritas['instant']} instant + {$prioritas['pickup']} ambil di toko menunggu" : 'Tidak ada pesanan prioritas' }}"
           class="px-3 py-2 rounded text-sm font-semibold border transition inline-flex items-center gap-1.5
                  {{ $prioritasOn ? 'bg-orange-600 border-orange-600 text-white' : 'bg-white border-orange-300 text-orange-700 hover:bg-orange-50' }}">
            ⚡ Instant / 🏬 Ambil Toko
            @if($prioritas['instant'] > 0)
                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-black leading-none
                             {{ $prioritasOn ? 'bg-white/25 text-white' : 'bg-orange-600 text-white' }}">⚡{{ $prioritas['instant'] }}</span>
            @endif
            @if($prioritas['pickup'] > 0)
                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-black leading-none
                             {{ $prioritasOn ? 'bg-white/25 text-white' : 'bg-amber-500 text-white' }}">🏬{{ $prioritas['pickup'] }}</span>
            @endif
            @if($prioritas['total'] === 0)
                <span class="text-[10px] font-black opacity-50">0</span>
            @endif
        </a>
    @endisset

    <button type="submit" class="text-sm px-3 py-2 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">Cari</button>

    @if(request('q') || request('channel') || request('courier') || request('prioritas'))
        <a href="{{ request()->url() . (request('tahap') ? '?tahap=' . request('tahap') : (request('resi') ? '?resi=' . request('resi') : '')) }}"
           class="text-xs text-gray-400 hover:text-gray-600 font-semibold">✕ Reset</a>
    @endif
</form>
