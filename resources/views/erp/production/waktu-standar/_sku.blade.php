{{--
    Satu SKU di layar Waktu Standar: baris ringkas + rincian sampel, dalam <tbody> sendiri supaya
    bisa diganti utuh setelah coret/simpan lewat AJAX (fungsi coret() & simpanBaris() di index).
    $u = satu elemen WaktuStandarService::usulan(); $buka = rincian terbuka.
--}}
@php
    $f = fn ($v, $d = 2) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $pid = $u['product_id'];
    $st  = $u['berlaku'];
    $takWajar = $u['n'] - $u['n_wajar'] - $u['n_coret'];
    $usulanTeks = $u['rata'] !== null ? $f($u['rata']) : '';
@endphp
<tbody id="sku-{{ $pid }}" x-data="{ buka: {{ ($buka ?? false) ? 'true' : 'false' }} }" class="border-t border-gray-100">
    <tr class="align-top">
        <td class="px-4 py-2">
            <div class="font-bold">{{ $u['sku'] }}</div>
            <div class="text-xs text-gray-500">{{ $u['nama'] }}</div>
        </td>
        <td class="px-3 py-2 text-right whitespace-nowrap">
            <span class="{{ $u['cukup'] ? '' : 'text-amber-700' }}">{{ $u['n_wajar'] }}</span>
            <span class="text-xs text-gray-400">OP · {{ $f($u['pcs'], 0) }} pcs</span>
            @if($u['n_coret'])
                <div class="text-[11px] text-gray-500">{{ $u['n_coret'] }} dicoret</div>
            @endif
            @if($takWajar > 0)
                <div class="text-[11px] text-red-600">{{ $takWajar }} tak wajar</div>
            @endif
        </td>
        <td class="px-3 py-2 text-right whitespace-nowrap">
            @if($u['rata'] !== null)
                <b class="{{ $u['cukup'] ? '' : 'text-gray-400' }}">{{ $usulanTeks }}</b>
                <div class="text-[11px] text-gray-400">median {{ $f($u['median']) }}</div>
                @unless($u['cukup'])<div class="text-[11px] text-amber-700">sampel sedikit — isi manual</div>@endunless
            @else
                <span class="text-gray-400">—</span>
            @endif
        </td>
        <td class="px-3 py-2 text-right text-xs text-gray-500 whitespace-nowrap">
            {{ $u['p25'] !== null ? $f($u['p25']) . ' – ' . $f($u['p75']) : '—' }}
        </td>
        <td class="px-3 py-2 text-right whitespace-nowrap">
            @if($st)
                <b class="text-emerald-700">{{ $f($st->minutes_per_unit) }}</b>
                <div class="text-[11px] text-gray-400">sejak {{ $st->effective_from->format('d/m/Y') }} · {{ ['median' => 'median', 'rata' => 'rata-rata'][$st->source] ?? 'manual' }}</div>
            @else
                <span class="text-xs text-red-600">belum ada</span>
            @endif
        </td>
        <td class="px-3 py-2">
            <div class="flex items-center gap-1">
                <input type="text" inputmode="decimal" name="standar[{{ $pid }}][menit]" data-menit
                       value="{{ $st ? $f($st->minutes_per_unit) : '' }}"
                       @if($u['cukup']) data-usulan-cukup="{{ $usulanTeks }}" @endif
                       placeholder="{{ $usulanTeks ?: 'mnt/pcs' }}"
                       @keydown.enter.prevent="simpanBaris({{ $pid }})"
                       class="w-24 border border-gray-200 rounded-lg px-2 py-1 text-right">
                <button type="button" @click="simpanBaris({{ $pid }})" title="Simpan standar SKU ini saja (Enter)"
                        class="px-2 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-semibold">simpan</button>
            </div>
            @if($usulanTeks)
                <button type="button" class="text-[11px] text-blue-700 hover:underline"
                        @click="$el.closest('td').querySelector('[data-menit]').value = '{{ $usulanTeks }}'">pakai usulan</button>
            @endif
            <input type="hidden" name="standar[{{ $pid }}][usulan]" value="{{ $usulanTeks }}" data-usulan>
            <input type="hidden" name="standar[{{ $pid }}][sampel]" value="{{ $u['n_wajar'] }}" data-sampel>
        </td>
        <td class="px-2 py-2 text-right">
            @if($u['n'])
                <button type="button" class="text-xs text-gray-500 hover:text-gray-800" @click="buka = !buka"
                        x-text="buka ? 'tutup' : 'rincian'"></button>
            @endif
        </td>
    </tr>
    @if($u['n'])
        <tr x-show="buka" x-cloak class="bg-gray-50">
            <td colspan="7" class="px-6 py-2">
                <table class="w-full text-xs">
                    <thead class="text-gray-400">
                        <tr><th class="text-left py-1">OP</th><th class="text-left">Selesai</th><th class="text-left">Pelaksana</th>
                            <th class="text-right">Pcs</th><th class="text-right">Menit timer</th><th class="text-right">Mnt/pcs</th><th class="text-right">vs usulan</th><th></th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach($u['catatan'] as $c)
                        <tr class="{{ $c['dicoret'] ? 'text-gray-400 line-through' : ($c['wajar'] ? '' : 'text-red-600') }}">
                            <td class="py-0.5"><a href="{{ route('production.orders.show', $c['op_id']) }}" class="hover:underline">{{ $c['op'] }}</a></td>
                            <td>{{ $c['selesai']?->format('d/m/Y') }}</td>
                            <td>{{ $c['pelaksana'] ?: '—' }}</td>
                            <td class="text-right">{{ $f($c['qty'], 0) }}</td>
                            <td class="text-right">{{ $f($c['menit'], 0) }}</td>
                            <td class="text-right font-semibold">{{ $f($c['mpp']) }}</td>
                            <td class="text-right">{{ $u['rata'] ? $f($u['rata'] / $c['mpp'] * 100, 0) . '%' : '—' }}</td>
                            <td class="pl-2">{{ $c['dicoret'] ? 'dicoret' : ($c['wajar'] ? '' : 'tak wajar') }}</td>
                            <td class="text-right">
                                <button type="button" @click="coret({{ $c['step_id'] }}, {{ $c['dicoret'] ? 0 : 1 }}, {{ $pid }})"
                                        class="inline-block px-2 py-0.5 rounded border text-[11px] font-semibold {{ $c['dicoret'] ? 'border-blue-300 text-blue-700 hover:bg-blue-50' : 'border-gray-300 text-gray-600 hover:bg-red-50 hover:text-red-700 hover:border-red-300' }}">
                                    {{ $c['dicoret'] ? 'pulihkan' : 'coret' }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <p class="text-[11px] text-gray-400 mt-1">"vs usulan" = efisiensi terhadap rata-rata: di atas 100% lebih cepat. Tak wajar (merah) otomatis tidak dihitung; coret yang lain bila menyimpang (salah qty, timer lupa dijeda, dll.).</p>
            </td>
        </tr>
    @endif
</tbody>
