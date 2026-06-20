{{-- Filter bar pemrosesan pesanan. Param: $couriers (Collection nama kurir). --}}
<form method="GET" class="mb-3 flex items-center gap-2 flex-wrap">
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

    @if($resiFilter ?? false)
        <select name="resi" onchange="this.form.submit()" class="border rounded px-3 py-2 text-sm bg-white">
            <option value="">Semua Status Resi</option>
            <option value="belum_generate" @selected(request('resi') === 'belum_generate')>Belum di-generate</option>
            <option value="belum_cetak" @selected(request('resi') === 'belum_cetak')>Belum dicetak</option>
            <option value="sudah_cetak" @selected(request('resi') === 'sudah_cetak')>Sudah dicetak</option>
        </select>
    @endif

    <button type="submit" class="text-sm px-3 py-2 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">Cari</button>

    @if(request('q') || request('channel') || request('courier') || request('resi'))
        <a href="{{ url()->current() }}" class="text-xs text-gray-400 hover:text-gray-600 font-semibold">✕ Reset</a>
    @endif
</form>
