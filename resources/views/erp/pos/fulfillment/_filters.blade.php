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

    {{-- Kurir: boleh centang beberapa. Tidak langsung kirim tiap centang (halaman
         memuat ulang di tengah memilih), tapi lewat tombol Terapkan / Cari.
         `courier` string tunggal dari tautan lama tetap terbaca lewat (array). --}}
    @php
        $kurirDipilih = array_values(array_filter(array_map('strval', (array) request('courier', []))));
    @endphp
    <details class="relative" data-filter-kurir>
        <summary class="list-none cursor-pointer border rounded px-3 py-2 text-sm bg-white min-w-[16rem]
                        flex items-center justify-between gap-2 {{ $kurirDipilih ? 'border-blue-400 text-blue-700 font-semibold' : '' }}">
            <span class="truncate max-w-[18rem]">
                @if(! $kurirDipilih)
                    Semua Kurir
                @elseif(count($kurirDipilih) === 1)
                    {{ $kurirDipilih[0] }}
                @else
                    {{ count($kurirDipilih) }} kurir: {{ implode(', ', $kurirDipilih) }}
                @endif
            </span>
            <span class="text-gray-400 text-xs">▾</span>
        </summary>
        <div class="absolute z-30 mt-1 w-72 bg-white border rounded-lg shadow-lg p-2">
            <div class="max-h-72 overflow-y-auto space-y-0.5">
                @forelse($couriers ?? [] as $c)
                    <label class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-gray-50 text-sm cursor-pointer">
                        <input type="checkbox" name="courier[]" value="{{ $c }}" class="rounded"
                               @checked(in_array((string) $c, $kurirDipilih, true))>
                        <span>{{ $c }}</span>
                    </label>
                @empty
                    <div class="px-2 py-1.5 text-xs text-gray-400">Belum ada kurir di daftar ini.</div>
                @endforelse
            </div>
            <div class="flex items-center justify-between gap-2 border-t mt-2 pt-2">
                <button type="button" class="text-xs text-gray-500 hover:text-gray-700 font-semibold"
                        onclick="this.closest('[data-filter-kurir]').querySelectorAll('input[type=checkbox]').forEach(c => c.checked = false)">
                    Kosongkan
                </button>
                <button type="submit" class="text-xs px-3 py-1.5 rounded bg-blue-600 text-white font-semibold hover:bg-blue-700">Terapkan</button>
            </div>
        </div>
    </details>
    <script>
        /* Panel kurir ditutup saat klik di luar — <details> tidak melakukannya sendiri. */
        document.addEventListener('click', (e) => {
            document.querySelectorAll('[data-filter-kurir][open]').forEach((d) => {
                if (!d.contains(e.target)) d.removeAttribute('open');
            });
        });
    </script>

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
