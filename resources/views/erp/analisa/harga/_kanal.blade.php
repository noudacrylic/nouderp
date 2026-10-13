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
    $keep   = collect(request()->query())->except('kanal', 'page', 'fee_pct', 'fee_rp', 'fee_po', 'fee_reset', 'fee_form', 'fee_mode')->all();

    // Tiga sumber potongan (dasar / real / andaian) — dipilih per kanal, tersimpan di sesi.
    $feeAsli    = $channel['fee_actual'] ?? $channel['fee'];
    $feeAndaian = (bool) ($channel['fee_assumed'] ?? false);
    $feeMode    = $channel['fee_mode'] ?? 'dasar';
    $feeReal    = $channel['fee_real'] ?? null;
    $andaian    = $channel['fee_andaian'] ?? null;
    $keepFee    = collect(request()->query())->except('fee_pct', 'fee_rp', 'fee_po', 'fee_reset', 'fee_form', 'fee_mode', 'page')->all();
    $angkaK     = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $ringkas    = fn (array $f) => $pctK($f['percent']) . ' + ' . $rpK($f['fixed'])
                    . (($f['preorder'] ?? 0) > 0 ? ' · PO +' . $pctK($f['preorder']) : '');
    $warnaMode  = ['dasar' => 'text-rose-600', 'real' => 'text-emerald-700', 'andaian' => 'text-amber-600'];
    $namaMode   = ['dasar' => 'Dasar', 'real' => 'Real', 'andaian' => 'Andaian'];
    $svcHarga   = \App\Modules\Analysis\Services\ChannelPricingService::class;
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
                @if($channel['fee_fallback'] ?? false)
                    Belum ada penyusun — memakai potongan akuntansi ·
                @elseif($channel['components']->isEmpty())
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
                <div class="text-[11px] font-bold uppercase {{ $warnaMode[$feeMode] }}">
                    Total potongan ({{ $namaMode[$feeMode] }})
                </div>
                <div class="text-lg font-black {{ $warnaMode[$feeMode] }}">
                    {{ $pctK($channel['fee']['percent']) }}
                    @if($channel['fee']['fixed'] > 0)
                        <span class="text-slate-400 font-bold">+</span> {{ $rpK($channel['fee']['fixed']) }}
                    @endif
                </div>
                @if(($channel['fee']['preorder'] ?? 0) > 0)
                    <div class="text-[11px] font-bold {{ $warnaMode[$feeMode] }}">
                        + {{ $pctK($channel['fee']['preorder']) }} khusus produk preorder
                    </div>
                @endif
                @if($feeMode !== 'dasar')
                    <div class="text-[10px] text-slate-500 font-semibold">
                        dasarnya {{ $pctK($feeAsli['percent']) }} + {{ $rpK($feeAsli['fixed']) }}
                    </div>
                @elseif($channel['fee_fallback'] ?? false)
                    <div class="text-[10px] text-slate-400">dari potongan akuntansi &mdash; belum diurai jadi penyusun</div>
                @else
                    <div class="text-[10px] text-slate-400">biaya tetap ditanggung sekali per pesanan</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Sumber potongan: DASAR (setingan / integrasi Jubelio), REAL (pesanan yang sudah
         selesai), ANDAIAN (ketikan sendiri). Pilihannya tersimpan per kanal dan ikut berpindah
         sub-tab. Andaian tetap tersimpan walau sumbernya dipindah — hilang hanya lewat "Lepas".
         Lihat ProductPriceController::withFeeAssumption & ChannelPricingService::realFee. --}}
    @if($channel['kind'] === 'marketplace')
        @php
            $pilihan = [
                'dasar'   => ['Dasar', $ringkas($feeAsli), true],
                'real'    => ['Real', $feeReal ? $ringkas($feeReal) . ' · ' . $feeReal['orders'] . ' pesanan' : 'belum cukup data', (bool) $feeReal],
                'andaian' => ['Andaian', $andaian ? $ringkas($andaian) : 'belum diisi', (bool) $andaian],
            ];
        @endphp
        <div class="mt-3 border-t border-slate-100 pt-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="text-[11px] font-bold text-slate-500 mr-1">Sumber potongan:</span>
            @foreach($pilihan as $m => [$judul, $isi, $bisa])
                @if($bisa)
                    <a href="{{ route($routes[$tab], array_merge($keepFee, ['kanal' => $channel['key'], 'fee_mode' => $m])) }}"
                       class="px-3 py-1.5 rounded-xl border font-semibold
                              {{ $feeMode === $m ? 'bg-slate-800 border-slate-800 text-white' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50' }}">
                        {{ $judul }} <span class="font-normal {{ $feeMode === $m ? 'text-slate-300' : 'text-slate-400' }}">{{ $isi }}</span>
                    </a>
                @else
                    <span class="px-3 py-1.5 rounded-xl border border-dashed border-slate-200 text-slate-400 cursor-not-allowed"
                          title="{{ $m === 'real'
                                ? 'Butuh minimal ' . $svcHarga::REAL_MIN_PESANAN . ' pesanan selesai dengan rincian potongan final dalam ' . $svcHarga::REAL_HARI . ' hari terakhir.'
                                : 'Isi andaian di bawah lalu terapkan.' }}">
                        {{ $judul }} <span class="font-normal">{{ $isi }}</span>
                    </span>
                @endif
            @endforeach
        </div>

        @if($feeReal)
            <div class="mt-2 text-[11px] text-slate-500 leading-relaxed">
                <span class="font-semibold text-emerald-700">Real</span> &mdash; dari {{ $feeReal['orders'] }} pesanan selesai
                {{ $feeReal['days'] }} hari terakhir (dasar persen = nilai barang):
                @foreach($feeReal['rincian'] as $r)
                    {{ $r['label'] }} {{ $r['jenis'] === 'tetap' ? $rpK($r['nilai']) . '/pesanan' : $pctK($r['nilai']) }}{{ !$loop->last ? ' ·' : '' }}
                @endforeach
                @if($feeReal['preorder'] !== null)
                    · <span class="font-semibold">biaya pre-order {{ $pctK($feeReal['preorder']) }}</span>
                    (selisih {{ $feeReal['orders_preorder'] }} pesanan preorder vs non-preorder)
                @else
                    · biaya pre-order belum terbaca ({{ $feeReal['orders_preorder'] }} pesanan preorder, butuh {{ $svcHarga::REAL_MIN_PREORDER }})
                @endif
                <span class="text-slate-400">· affiliate &amp; pajak tidak termasuk.</span>
            </div>
        @endif
    @endif

    {{-- Andaian — "kalau potongan marketplace naik jadi sekian, harga saya masih untung?".
         Menerapkan andaian sekaligus memilihnya sebagai sumber. Angkanya tetap tersimpan saat
         sumber dipindah ke Dasar/Real, dan hanya dibuang tombol "Lepas". --}}
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
            <label class="block text-[11px] font-bold {{ $andaian ? 'text-amber-600' : 'text-slate-500' }} mb-1">
                Andaikan potongan (%)
            </label>
            <input type="number" step="0.01" min="0" name="fee_pct"
                   value="{{ $andaian ? $angkaK($andaian['percent']) : '' }}"
                   placeholder="{{ $angkaK($feeAsli['percent']) }}"
                   class="border {{ $andaian ? 'border-amber-300' : 'border-slate-200' }} rounded-xl px-3 py-1.5 text-sm w-28 text-right">
        </div>
        <div>
            <label class="block text-[11px] font-bold {{ $andaian ? 'text-amber-600' : 'text-slate-500' }} mb-1">
                Biaya tetap (Rp)
            </label>
            <input type="text" name="fee_rp"
                   value="{{ $andaian ? number_format($andaian['fixed'], 0, ',', '.') : '' }}"
                   placeholder="{{ number_format($feeAsli['fixed'], 0, ',', '.') }}"
                   class="rupiah-input border {{ $andaian ? 'border-amber-300' : 'border-slate-200' }} rounded-xl px-3 py-1.5 text-sm w-32 text-right">
        </div>
        {{-- Ditambahkan ke persen potongan HANYA untuk produk berjenis preorder — mis. Layanan
             Produk Pre-Order Shopee 3% per unit (berlaku 2026). Produk ready tidak terpengaruh. --}}
        <div>
            <label class="block text-[11px] font-bold {{ $andaian ? 'text-amber-600' : 'text-slate-500' }} mb-1"
                   title="Ditambahkan ke potongan khusus produk berjenis preorder">
                Biaya Pre-Order (%)
            </label>
            <input type="number" step="0.01" min="0" name="fee_po"
                   value="{{ $andaian && $andaian['preorder'] > 0 ? $angkaK($andaian['preorder']) : '' }}"
                   placeholder="{{ $feeReal && $feeReal['preorder'] !== null ? $angkaK($feeReal['preorder']) : '0' }}"
                   class="border {{ $andaian ? 'border-amber-300' : 'border-slate-200' }} rounded-xl px-3 py-1.5 text-sm w-28 text-right">
        </div>
        <button class="border border-amber-300 text-amber-700 hover:bg-amber-50 px-3.5 py-1.5 rounded-xl text-xs font-bold">
            Terapkan andaian
        </button>
        @if($andaian)
            <a href="{{ route($routes[$tab], array_merge($keepFee, ['kanal' => $channel['key'], 'fee_reset' => 1])) }}"
               class="border border-slate-300 text-slate-600 hover:bg-slate-50 px-3.5 py-1.5 rounded-xl text-xs font-bold"
               title="Buang angka andaian kanal ini">Lepas</a>
        @endif
        <span class="text-[11px] {{ $feeAndaian ? 'text-amber-600' : 'text-slate-400' }} ml-1">
            @if($andaian)
                Andaian <strong>tetap tersimpan</strong> walau sumber dipindah ke Dasar/Real &mdash; tekan
                <strong>Lepas</strong> untuk membuangnya. Hanya untuk {{ $channel['label'] }}, dan hanya di layar kamu.
            @else
                Sekali diterapkan, andaian tersimpan per kanal sampai dilepas &mdash; hanya di layar kamu.
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
