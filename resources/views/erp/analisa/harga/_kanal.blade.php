{{--
    Deretan kanal + rincian potongannya. Param: $channels, $channel, $tab.

    Potongan sengaja ditampilkan terurai, bukan sebagai satu angka gabungan: yang menentukan
    harga bukan "18%", melainkan sadar bahwa sebagiannya biaya tetap yang ditanggung sekali
    per pesanan — itulah kenapa barang murah terasa jauh lebih berat potongannya.
--}}
@php
    $rpK    = fn (?float $v) => 'Rp' . number_format((float) $v, 0, ',', '.');
    $pctK   = fn (?float $v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') . '%';
    $routes = ['harga' => 'analisa.harga.index', 'afiliasi' => 'analisa.harga.afiliasi',
               'grosir' => 'analisa.harga.grosir', 'promo' => 'analisa.harga.promo',
               'promo-produk' => 'analisa.harga.promo.produk'];
    // Pindah kanal TIDAK boleh menyeret angka andaian kanal sebelumnya: potongan Shopee
    // yang diandaikan 20% bukan andaian yang sah untuk Tokopedia. Andaiannya sendiri sudah
    // tersimpan di sesi per kanal, jadi tidak ada yang hilang dengan membuangnya dari URL.
    $keep   = collect(request()->query())->except('kanal', 'page', 'fee_pct', 'fee_rp', 'fee_reset', 'fee_form')->all();

    // Potongan andaian: tersimpan di sesi per kanal, aslinya tetap dibawa berdampingan.
    $feeAsli    = $channel['fee_actual'] ?? $channel['fee'];
    $feeAndaian = (bool) ($channel['fee_assumed'] ?? false);
    $keepFee    = collect(request()->query())->except('fee_pct', 'fee_rp', 'fee_reset', 'fee_form', 'page')->all();
@endphp

<div class="flex flex-wrap items-center gap-2 mb-4">
    @foreach($channels as $key => $c)
        <a href="{{ route($routes[$tab], array_merge($keep, ['kanal' => $key])) }}"
           class="px-3.5 py-1.5 rounded-full text-sm font-semibold border transition
                  {{ $key === $channel['key']
                        ? 'bg-indigo-600 border-indigo-600 text-white shadow-sm'
                        : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50' }}">
            {{ $c['label'] }}
            @if($c['kind'] === 'marketplace')
                <span class="text-[10px] font-bold {{ $key === $channel['key'] ? 'text-indigo-100' : 'text-slate-400' }}">
                    {{ $pctK($c['fee']['percent']) }}
                </span>
            @endif
        </a>
    @endforeach
</div>

<div class="bg-white border border-slate-100 rounded-2xl shadow-sm px-5 py-4 mb-4">
    <div class="flex flex-wrap items-start justify-between gap-5">
        <div class="min-w-[260px]">
            <div class="text-sm font-bold text-slate-800">Potongan {{ $channel['label'] }}</div>
            <div class="text-[11px] text-slate-500 mt-0.5">
                @if($channel['components']->isEmpty())
                    Tidak ada potongan — harga jual utuh jadi pendapatan.
                @else
                    {{ $channel['components']->count() }} penyusun ·
                @endif
                <button type="button" class="text-blue-600 font-semibold hover:underline"
                        onclick="document.getElementById('potongan-editor').classList.toggle('hidden')">
                    atur potongan
                </button>
            </div>
            @if($channel['note'])
                <div class="text-[11px] text-slate-400 mt-2 max-w-md leading-relaxed">{{ $channel['note'] }}</div>
            @endif
        </div>

        <div class="flex flex-wrap gap-5 text-right">
            @foreach($channel['components'] as $comp)
                <div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase">{{ $comp->label }}</div>
                    <div class="text-base font-black text-slate-700">
                        {{ $comp->percent > 0 ? $pctK($comp->percent) : $rpK($comp->fixed) }}
                    </div>
                    @unless($comp->include_accounting)
                        <div class="text-[10px] text-slate-400">tidak ikut akuntansi</div>
                    @endunless
                </div>
            @endforeach
            <div class="border-l border-slate-100 pl-5">
                <div class="text-[11px] font-bold uppercase {{ $feeAndaian ? 'text-amber-600' : 'text-slate-400' }}">
                    {{ $feeAndaian ? 'Total potongan (andaian)' : 'Total potongan' }}
                </div>
                <div class="text-lg font-black {{ $feeAndaian ? 'text-amber-600' : 'text-rose-600' }}">
                    {{ $pctK($channel['fee']['percent']) }}
                    @if($channel['fee']['fixed'] > 0)
                        <span class="text-slate-400 font-bold">+</span> {{ $rpK($channel['fee']['fixed']) }}
                    @endif
                </div>
                @if($feeAndaian)
                    <div class="text-[10px] text-amber-600 font-semibold">
                        aslinya {{ $pctK($feeAsli['percent']) }} + {{ $rpK($feeAsli['fixed']) }}
                    </div>
                @else
                    <div class="text-[10px] text-slate-400">biaya tetap ditanggung sekali per pesanan</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Potongan andaian — "kalau potongan marketplace naik jadi sekian, harga saya masih
         untung?". Terpasang sampai dilepas: menimbang harga butuh bolak-balik mencari,
         mengurutkan, dan pindah sub-tab, dan andaian yang lenyap di tengah jalan justru
         paling berbahaya — angkanya kembali ke potongan asli tanpa memberi tahu siapa pun.
         `fee_form` adalah penanda supaya "kedua kolom dikosongkan" bisa dibedakan dari
         kunjungan biasa; tanpa itu andaiannya tidak bisa dihapus lewat mengosongkan kolom. --}}
    <form method="GET" class="mt-3 border-t border-slate-100 pt-3 flex flex-wrap items-end gap-2">
        <input type="hidden" name="fee_form" value="1">
        @foreach($keepFee as $k => $v)
            @if(is_array($v))
                @foreach($v as $vv)<input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">@endforeach
            @else
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endif
        @endforeach
        <div>
            <label class="block text-[11px] font-bold {{ $feeAndaian ? 'text-amber-600' : 'text-slate-500' }} mb-1">
                Andaikan potongan (%)
            </label>
            <input type="number" step="0.01" min="0" name="fee_pct"
                   value="{{ $feeAndaian ? rtrim(rtrim(number_format($channel['fee']['percent'], 2, '.', ''), '0'), '.') : '' }}"
                   placeholder="{{ rtrim(rtrim(number_format($feeAsli['percent'], 2, '.', ''), '0'), '.') }}"
                   class="border {{ $feeAndaian ? 'border-amber-300' : 'border-slate-200' }} rounded-xl px-3 py-1.5 text-sm w-28 text-right">
        </div>
        <div>
            <label class="block text-[11px] font-bold {{ $feeAndaian ? 'text-amber-600' : 'text-slate-500' }} mb-1">
                Biaya tetap (Rp)
            </label>
            <input type="text" name="fee_rp"
                   value="{{ $feeAndaian ? number_format($channel['fee']['fixed'], 0, ',', '.') : '' }}"
                   placeholder="{{ number_format($feeAsli['fixed'], 0, ',', '.') }}"
                   class="rupiah-input border {{ $feeAndaian ? 'border-amber-300' : 'border-slate-200' }} rounded-xl px-3 py-1.5 text-sm w-32 text-right">
        </div>
        <button class="border border-amber-300 text-amber-700 hover:bg-amber-50 px-3.5 py-1.5 rounded-xl text-xs font-bold">
            Terapkan andaian
        </button>
        @if($feeAndaian)
            <a href="{{ route($routes[$tab], array_merge($keepFee, ['kanal' => $channel['key'], 'fee_reset' => 1])) }}"
               class="text-xs text-amber-700 font-semibold hover:underline py-2">kembali ke potongan asli</a>
        @endif
        <span class="text-[11px] {{ $feeAndaian ? 'text-amber-600' : 'text-slate-400' }} ml-1">
            @if($feeAndaian)
                Andaian ini <strong>tetap terpasang</strong> sampai diganti atau dikembalikan &mdash;
                ikut berpindah sub-tab dan bertahan saat mencari/mengurutkan. Hanya berlaku untuk
                {{ $channel['label'] }}, dan hanya di layar kamu.
            @else
                Kosongkan untuk memakai potongan sebenarnya. Sekali diterapkan, andaiannya tetap
                terpasang sampai dikembalikan &mdash; per kanal, dan hanya di layar kamu.
            @endif
        </span>
    </form>

    {{-- Pembanding ke potongan versi akuntansi. Boleh berbeda (akuntansi tidak memuat biaya
         Jubelio), tapi bagian yang dicentang "ikut akuntansi" harus sama — kalau tidak,
         salah satunya sudah basi. --}}
    @if(!empty($channel['accounting']['customers']))
        <div class="text-[11px] text-slate-400 mt-3 border-t border-slate-100 pt-3">
            Versi akuntansi:
            @foreach($channel['accounting']['customers'] as $c)
                <span class="{{ $c['matches'] ? 'text-slate-500' : 'text-amber-600 font-semibold' }}">
                    {{ $c['name'] }} {{ $pctK($c['percent']) }} + {{ $rpK($c['fixed']) }}{{ !$loop->last ? ' ·' : '' }}
                </span>
            @endforeach
            @if(collect($channel['accounting']['customers'])->contains(fn ($c) => !$c['matches']))
                <span class="text-amber-600">— berbeda dengan penyusun yang dicentang ikut akuntansi
                    ({{ $pctK($channel['accounting']['analysis']['percent']) }} + {{ $rpK($channel['accounting']['analysis']['fixed']) }}).
                    Satu di antaranya sudah basi; yang dipakai jurnal tetap angka akuntansi.</span>
            @endif
            @if(!empty($channel['store_ids']))
                <span class="text-slate-300">·</span> toko Jubelio {{ implode(', ', $channel['store_ids']) }}
            @endif
        </div>
    @endif

    {{-- Editor penyusun potongan --}}
    <div id="potongan-editor" class="hidden mt-4 border-t border-slate-100 pt-4">
        <table class="w-full text-sm">
            <thead class="text-[10px] uppercase tracking-widest text-slate-400">
                <tr>
                    <th class="text-left font-black pb-2">Penyusun</th>
                    <th class="text-right font-black pb-2 w-28">Persen</th>
                    <th class="text-right font-black pb-2 w-32">Tetap (Rp)</th>
                    <th class="text-center font-black pb-2 w-32">Ikut akuntansi</th>
                    <th class="pb-2 w-24"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @foreach($channel['components'] as $comp)
                    <tr>
                        <td colspan="5" class="py-1.5">
                            <form method="POST" action="{{ route('analisa.harga.component.save') }}" class="flex items-center gap-2">
                                @csrf
                                <input type="hidden" name="id" value="{{ $comp->id }}">
                                <input type="hidden" name="channel" value="{{ $channel['key'] }}">
                                <input type="text" name="label" value="{{ $comp->label }}" class="border border-slate-200 rounded-lg px-2 py-1 text-sm flex-1">
                                <input type="number" step="0.01" min="0" max="100" name="percent" value="{{ rtrim(rtrim(number_format($comp->percent, 4, '.', ''), '0'), '.') ?: 0 }}"
                                       class="border border-slate-200 rounded-lg px-2 py-1 text-sm w-24 text-right">
                                <input type="text" name="fixed" value="{{ number_format($comp->fixed, 0, ',', '.') }}"
                                       class="rupiah-input border border-slate-200 rounded-lg px-2 py-1 text-sm w-28 text-right">
                                <label class="flex items-center gap-1 text-[11px] text-slate-500 w-32 justify-center">
                                    <input type="checkbox" name="include_accounting" value="1" @checked($comp->include_accounting)> ikut akuntansi
                                </label>
                                <button class="text-teal-700 hover:text-teal-900 text-xs font-bold">✓ Simpan</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="5" class="py-1.5">
                        <form method="POST" action="{{ route('analisa.harga.component.save') }}" class="flex items-center gap-2">
                            @csrf
                            <input type="hidden" name="channel" value="{{ $channel['key'] }}">
                            <input type="text" name="label" placeholder="Penyusun baru…" class="border border-slate-200 rounded-lg px-2 py-1 text-sm flex-1">
                            <input type="number" step="0.01" min="0" max="100" name="percent" placeholder="0" class="border border-slate-200 rounded-lg px-2 py-1 text-sm w-24 text-right">
                            <input type="text" name="fixed" placeholder="0" class="rupiah-input border border-slate-200 rounded-lg px-2 py-1 text-sm w-28 text-right">
                            <label class="flex items-center gap-1 text-[11px] text-slate-500 w-32 justify-center">
                                <input type="checkbox" name="include_accounting" value="1"> ikut akuntansi
                            </label>
                            <button class="text-blue-600 hover:text-blue-800 text-xs font-bold">+ Tambah</button>
                        </form>
                    </td>
                </tr>
            </tbody>
        </table>
        @if($channel['components']->isNotEmpty())
            <div class="flex flex-wrap gap-3 mt-3">
                @foreach($channel['components'] as $comp)
                    <form method="POST" action="{{ route('analisa.harga.component.destroy', $comp->id) }}"
                          onsubmit="return confirm('Hapus penyusun {{ $comp->label }}?')">
                        @csrf @method('DELETE')
                        <button class="text-[11px] text-red-500 hover:underline">hapus {{ $comp->label }}</button>
                    </form>
                @endforeach
            </div>
        @endif
    </div>
</div>
