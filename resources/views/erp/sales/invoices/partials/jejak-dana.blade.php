{{--
    Jejak dana faktur — HANYA di halaman detail, tidak ikut dicetak.
    Faktur di atas bercerita nilai jualnya apa adanya; bagian ini meneruskan ceritanya:
    b. Marketplace — dana ditahan, dipotong admin & pajak, sisa masuk Saldo Penjualan,
       lalu dicocokkan dengan laporan marketplace.
    c. Retur — rincian lengkap tiap retur beserta jurnalnya.
    Semua angka dibaca dari jurnal yang terposting (InvoiceTrailService), bukan dihitung ulang.
--}}
@php
    $mp     = $jejak['marketplace'] ?? null;
    $returs = $jejak['retur'] ?? [];
    $tahap  = $jejak['tahap'] ?? collect();
    $rp     = fn ($v) => ((float) $v < 0 ? '−Rp ' : 'Rp ') . number_format(abs((float) $v), 0, ',', '.');
    $tgl    = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '—';
@endphp

{{-- Tabel jurnal kecil — dipakai berulang di bawah. --}}
@php
    $tabelJurnal = function (array $baris) use ($rp) {
        if (!$baris) {
            return '<div class="text-[11px] text-gray-400 italic">tanpa jurnal</div>';
        }
        $h = '<table class="w-full text-[11px]"><tbody>';
        foreach ($baris as $b) {
            $kredit = $b['kredit'] > 0;
            $h .= '<tr class="border-t border-gray-50">'
                . '<td class="py-1 ' . ($kredit ? 'pl-5 text-gray-500' : 'text-gray-800 font-semibold') . '">'
                . ($kredit ? 'Cr ' : 'Dr ') . e($b['kode'] . ' ' . $b['akun'])
                . ($b['ket'] ? ' <span class="text-gray-400 font-normal">— ' . e($b['ket']) . '</span>' : '')
                . '</td>'
                . '<td class="py-1 text-right w-28 font-mono">' . ($b['debit'] > 0 ? e(number_format($b['debit'], 0, ',', '.')) : '') . '</td>'
                . '<td class="py-1 text-right w-28 font-mono text-gray-500">' . ($kredit ? e(number_format($b['kredit'], 0, ',', '.')) : '') . '</td>'
                . '</tr>';
        }
        return $h . '</tbody></table>';
    };
@endphp

{{-- ═══ b. MARKETPLACE ═══ --}}
@if($mp && $invoice->status !== 'draft')
<div class="mt-6 bg-white border border-gray-200 rounded-xl shadow-sm p-5">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="font-bold text-gray-800">🏪 Marketplace — perjalanan dana</h3>
            <p class="text-xs text-gray-500 mt-0.5">Apa yang terjadi pada uang faktur ini setelah terbit. Tidak ikut dicetak.</p>
        </div>
        @if($mp['cair'])
            <span class="text-xs font-bold px-3 py-1 rounded-full bg-green-100 text-green-700">✓ Sudah cair{{ $mp['tgl_cair'] ? ' · ' . $tgl($mp['tgl_cair']) : '' }}</span>
        @else
            <span class="text-xs font-bold px-3 py-1 rounded-full bg-sky-100 text-sky-700">⏸ Belum cair</span>
        @endif
    </div>

    {{-- Alur angka: kotor → potongan → masuk dompet --}}
    <div class="flex flex-wrap items-stretch gap-2 text-sm">
        <div class="flex-1 min-w-[140px] rounded-lg border border-gray-200 px-3 py-2">
            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Nilai faktur</div>
            <div class="font-bold text-gray-800">{{ $rp($mp['kotor']) }}</div>
        </div>
        @if($mp['dibalik'] > 0)
            <div class="self-center text-gray-400 font-bold">−</div>
            <div class="flex-1 min-w-[140px] rounded-lg border border-rose-200 bg-rose-50/50 px-3 py-2">
                <div class="text-[10px] font-black text-rose-400 uppercase tracking-widest">Dibalik retur</div>
                <div class="font-bold text-rose-700">{{ $rp($mp['dibalik']) }}</div>
            </div>
        @endif
        <div class="self-center text-gray-400 font-bold">−</div>
        <div class="flex-1 min-w-[140px] rounded-lg border border-gray-200 px-3 py-2">
            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Biaya admin</div>
            <div class="font-bold text-gray-800">{{ $mp['cair'] || $mp['gaya_lama'] ? $rp($mp['admin']) : '—' }}</div>
            <div class="text-[10px] text-gray-400">termasuk Hemat Biaya Kirim</div>
        </div>
        @if($mp['pajak'] > 0)
            <div class="self-center text-gray-400 font-bold">−</div>
            <div class="flex-1 min-w-[120px] rounded-lg border border-gray-200 px-3 py-2">
                <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Pajak</div>
                <div class="font-bold text-gray-800">{{ $rp($mp['pajak']) }}</div>
            </div>
        @endif
        <div class="self-center text-gray-400 font-bold">=</div>
        <div class="flex-1 min-w-[180px] rounded-lg border px-3 py-2 {{ $mp['dompet'] < 0 ? 'border-red-200 bg-red-50/50' : 'border-emerald-200 bg-emerald-50/50' }}">
            <div class="text-[10px] font-black uppercase tracking-widest {{ $mp['dompet'] < 0 ? 'text-red-400' : 'text-emerald-500' }}">Masuk Saldo Penjualan</div>
            <div class="font-bold {{ $mp['dompet'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $mp['cair'] ? $rp($mp['dompet']) : '—' }}</div>
            <div class="text-[10px] text-gray-400">{{ $mp['akun_dompet'] }}</div>
        </div>
    </div>

    {{-- Kalimat penjelas --}}
    <p class="text-xs text-gray-600 mt-3 leading-relaxed">
        @if($mp['gaya_lama'])
            Faktur gaya lama: biaya admin {{ $rp($mp['admin']) }} sudah dipotong langsung di faktur, jadi Grand Total di atas adalah nilai bersih.
        @elseif(!$mp['cair'])
            Pembeli sudah membayar; dananya masih <b>ditahan</b> di {{ $mp['akun_tahan'] }}. Biaya admin & pajak baru dipotong saat pesanan selesai (atau returnya diselesaikan).
        @else
            Pembeli membayar {{ $rp($mp['kotor']) }} yang ditahan di {{ $mp['akun_tahan'] }}.
            @if($mp['dibalik'] > 0) {{ $rp($mp['dibalik']) }} dikembalikan ke pembeli lewat retur. @endif
            Marketplace memotong biaya admin {{ $rp($mp['admin']) }}@if($mp['pajak'] > 0) dan pajak {{ $rp($mp['pajak']) }}@endif;
            @if($mp['dompet'] < 0)
                karena tak ada dana yang cair, <b>{{ $mp['akun_dompet'] }} terpotong {{ $rp(abs($mp['dompet'])) }}</b>.
            @else
                sisanya <b>{{ $rp($mp['dompet']) }}</b> masuk {{ $mp['akun_dompet'] }}.
            @endif
        @endif
    </p>

    {{-- Rekonsiliasi --}}
    <div class="mt-3 rounded-lg px-3 py-2 text-xs
        {{ $mp['rekon'] ? (abs($mp['rekon']['selisih']) > 0.5 ? 'bg-amber-50 border border-amber-200 text-amber-800' : 'bg-green-50 border border-green-200 text-green-800') : 'bg-gray-50 border border-gray-100 text-gray-500' }}">
        @if($mp['rekon'])
            <b>Rekonsiliasi {{ $mp['rekon']['nomor'] }}</b>{{ $mp['rekon']['posted'] ? '' : ' (draf)' }}:
            dana cair menurut laporan {{ $rp($mp['rekon']['net']) }} ·
            potongan aktual {{ $rp($mp['rekon']['aktual']) }} vs tercatat {{ $rp($mp['rekon']['tercatat']) }}
            @if(abs($mp['rekon']['selisih']) > 0.5)
                · selisih <b>{{ $rp($mp['rekon']['selisih']) }}</b> {{ $mp['rekon']['posted'] ? 'dibukukan' : 'akan dibukukan' }} ke Beban Admin.
            @else
                · <b>cocok ✓</b>
            @endif
        @elseif($mp['menunggu_rekon'])
            ⏳ Sudah ada di laporan rekonsiliasi, menunggu pesanan ini dituntaskan di ERP — dicocokkan otomatis sesudahnya.
        @else
            Belum ada di laporan rekonsiliasi marketplace.
        @endif
    </div>
</div>
@endif

{{-- ═══ c. RETUR ═══ --}}
@if(count($returs))
<div class="mt-6 bg-white border border-gray-200 rounded-xl shadow-sm p-5">
    <h3 class="font-bold text-gray-800 mb-4">↩️ Retur atas faktur ini</h3>

    <div class="space-y-4">
        @foreach($returs as $r)
            <div class="rounded-lg border {{ $r['void'] || $r['status'] === 'void' ? 'border-gray-200 opacity-60' : 'border-gray-200' }} p-4">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('sales.returns.show', $r['id']) }}" class="font-bold text-blue-600 hover:underline">{{ $r['nomor'] }}</a>
                        <span class="text-xs text-gray-500">{{ $tgl($r['tanggal']) }}</span>
                        <span class="text-[10px] font-black px-2 py-0.5 rounded-full uppercase
                            {{ $r['status'] === 'posted' ? 'bg-green-100 text-green-700' : ($r['status'] === 'draft' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-600') }}">
                            {{ $r['status'] === 'posted' ? 'Selesai' : ($r['status'] === 'draft' ? 'Draf' : 'Void') }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-600">
                        {{ $r['jenis'] }}@if($r['kasus']) · <b>{{ $r['kasus'] }}</b>@endif
                    </div>
                </div>

                <table class="w-full text-xs mb-3">
                    <thead>
                        <tr class="text-[10px] font-black text-gray-400 uppercase tracking-widest">
                            <th class="text-left py-1">Produk</th>
                            <th class="text-right py-1 w-16">Qty</th>
                            <th class="text-left py-1 w-32 pl-4">Kondisi</th>
                            <th class="text-right py-1 w-28">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($r['items'] as $it)
                            <tr class="border-t border-gray-50">
                                <td class="py-1 text-gray-800">{{ $it['nama'] }}</td>
                                <td class="py-1 text-right">{{ rtrim(rtrim(number_format($it['qty'], 2, ',', '.'), '0'), ',') }}</td>
                                <td class="py-1 pl-4 text-gray-600">{{ $it['kondisi'] }}</td>
                                <td class="py-1 text-right font-mono">{{ number_format($it['nilai'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="flex flex-wrap gap-4 text-xs mb-3">
                    <span class="text-gray-500">Nilai barang: <b class="text-gray-800">{{ $rp($r['nilai']) }}</b></span>
                    @if($r['dibalik'] !== null)
                        <span class="text-gray-500">Penjualan dibalik: <b class="{{ $r['dibalik'] > 0 ? 'text-rose-700' : 'text-green-700' }}">{{ $r['dibalik'] > 0 ? $rp($r['dibalik']) : 'tidak dibalik' }}</b></span>
                    @endif
                </div>

                @if($r['status'] !== 'draft')
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Jurnal retur (HPP + pembalikan)</div>
                            {!! $tabelJurnal($r['jurnal_retur']) !!}
                        </div>
                        <div>
                            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Jurnal penyelesaian pesanan</div>
                            {!! $tabelJurnal($r['jurnal_cair']) !!}
                        </div>
                    </div>
                @else
                    <div class="text-[11px] text-amber-700">Masih draf — belum ada jurnal. <a href="{{ route('sales.returns.edit', $r['id']) }}" class="underline">Buka retur</a></div>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- ═══ Semua jurnal terkait, urut waktu ═══ --}}
@if(count($tahap) && $invoice->status !== 'draft')
<details class="mt-6 bg-white border border-gray-200 rounded-xl shadow-sm group">
    <summary class="cursor-pointer select-none px-5 py-4 font-bold text-gray-800 flex items-center justify-between">
        <span>📒 Jurnal terkait faktur ini <span class="text-xs font-normal text-gray-400">({{ count($tahap) }})</span></span>
        <span class="text-xs text-gray-400 group-open:hidden">tampilkan ▸</span>
        <span class="text-xs text-gray-400 hidden group-open:inline">sembunyikan ▾</span>
    </summary>
    <div class="px-5 pb-5 space-y-4">
        @foreach($tahap as $t)
            <div class="{{ $t['void'] ? 'opacity-50' : '' }}">
                <div class="flex items-baseline justify-between mb-1">
                    <div class="text-xs font-bold text-gray-700">
                        {{ $loop->iteration }}. {{ $t['judul'] }}
                        @if($t['void'])<span class="text-[10px] text-red-600 font-black ml-1">VOID</span>@endif
                    </div>
                    <div class="text-[11px] text-gray-400">{{ $tgl($t['tanggal']) }} · {{ $t['nomor'] }}</div>
                </div>
                {!! $tabelJurnal($t['baris']) !!}
            </div>
        @endforeach
    </div>
</details>
@endif
