@extends('layouts.erp')

@php
    $statusLabels = \App\Modules\Finance\Services\PphFinalReportService::STATUS_LABEL;
    $fmt = fn ($n) => $n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.');
    $r = $ringkas;
    $masaLabel = $r['masa']->translatedFormat('F Y');
    $prev = $r['masa']->copy()->subMonthNoOverflow();
    $next = $r['masa']->copy()->addMonthNoOverflow();
    [$stLabel, $stCls] = match ($r['status']) {
        'lunas'    => ['Lunas', 'bg-green-100 text-green-700'],
        'lebih'    => ['Lebih bayar', 'bg-blue-100 text-blue-700'],
        'kurang'   => ['Kurang setor', 'bg-amber-100 text-amber-800'],
        'belum'    => ['Belum setor', 'bg-red-100 text-red-700'],
        'berjalan' => ['Masa berjalan', 'bg-gray-100 text-gray-600'],
        default    => ['Nihil', 'bg-gray-100 text-gray-500'],
    };
    $statusCls = ['dipungut' => 'bg-emerald-100 text-emerald-700', 'menunggu' => 'bg-sky-100 text-sky-700', 'sendiri' => 'bg-orange-100 text-orange-700'];
    $sumberLabel = ['aktual' => 'angka asli', 'jurnal' => 'jurnal settlement', 'taksiran' => 'taksiran'];
    $bayarLabels = \App\Modules\Finance\Services\PphFinalReportService::BAYAR_LABEL;
    $bayarCls = [
        'lunas' => 'bg-green-100 text-green-700', 'sebagian' => 'bg-amber-100 text-amber-800', 'belum' => 'bg-red-100 text-red-700',
        'bebas' => 'bg-gray-100 text-gray-600', 'nihil' => 'bg-gray-100 text-gray-500',
        'dipotong_mp' => 'bg-emerald-100 text-emerald-700', 'menunggu_mp' => 'bg-sky-100 text-sky-700',
    ];
@endphp

@section('content')
<div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
    <div>
        <div class="text-xs text-gray-500"><a href="{{ route('accounting.reports.pajak.index', ['tahun' => $tahun]) }}" class="text-blue-600 hover:underline">Laporan Pajak {{ $tahun }}</a> ›</div>
        <h1 class="text-lg font-semibold flex items-center gap-2">
            PPh Final 0,5% · Masa {{ $masaLabel }}
            <span class="px-1.5 py-0.5 rounded text-xs font-normal {{ $stCls }}">{{ $stLabel }}</span>
        </h1>
    </div>
    <div class="flex gap-2 items-center print:hidden">
        <a href="{{ route('accounting.reports.pajak.masa', [$prev->year, $prev->month]) }}" class="border rounded px-2 py-1.5 text-sm">‹ {{ $prev->translatedFormat('M') }}</a>
        <a href="{{ route('accounting.reports.pajak.masa', [$next->year, $next->month]) }}" class="border rounded px-2 py-1.5 text-sm">{{ $next->translatedFormat('M') }} ›</a>
        <a href="{{ route('accounting.reports.pajak.excel', [$tahun, $bulan]) }}" class="bg-emerald-600 text-white px-3 py-2 rounded text-sm">Excel</a>
        <button onclick="window.print()" class="bg-gray-700 text-white px-3 py-2 rounded text-sm">Cetak</button>
    </div>
</div>

@foreach($peringatan as $msg)
    <div class="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 print:hidden">⚠ {{ $msg }}</div>
@endforeach

<div class="grid lg:grid-cols-3 gap-4 mb-4">
    {{-- Perhitungan --}}
    <div class="bg-white rounded shadow p-4 lg:col-span-2">
        <h2 class="text-sm font-semibold mb-2">Perhitungan</h2>
        <table class="w-full text-sm">
            <tbody>
                <tr><td class="py-1">Omzet bruto ({{ $r['faktur_count'] }} faktur)</td><td class="py-1 text-right tabular-nums">{{ $fmt($r['omzet_bruto']) }}</td></tr>
                @if($r['omzet_luar'] != 0)
                    <tr><td class="py-1">Omzet di luar ERP{{ $omzetLuar?->notes ? ' (' . $omzetLuar->notes . ')' : '' }}</td><td class="py-1 text-right tabular-nums">{{ $fmt($r['omzet_luar']) }}</td></tr>
                @endif
                <tr><td class="py-1">Retur bulan ini</td><td class="py-1 text-right tabular-nums">{{ $fmt(-$r['retur']) }}</td></tr>
                <tr class="border-t font-medium"><td class="py-1">Omzet neto</td><td class="py-1 text-right tabular-nums">{{ $fmt($r['omzet_neto']) }}</td></tr>
                <tr class="text-gray-600"><td class="py-1 pl-4">— dipungut marketplace (sudah lunas / menunggu selesai)</td><td class="py-1 text-right tabular-nums">{{ $fmt($r['omzet_dipungut']) }}</td></tr>
                <tr class="text-gray-600"><td class="py-1 pl-4">— setor sendiri (kasir, ERP, website, pesanan marketplace yang masuk sebelum tanggal pungut)</td><td class="py-1 text-right tabular-nums">{{ $fmt($r['omzet_sendiri']) }}</td></tr>
                @if($r['omzet_bebas'] > 0)
                    <tr class="text-emerald-700"><td class="py-1 pl-4">— bebas pajak (sisa jatah Rp500 jt; omzet s/d bulan lalu {{ $fmt($kumulatif_sebelum) }})</td><td class="py-1 text-right tabular-nums">{{ $fmt(-$r['omzet_bebas']) }}</td></tr>
                @endif
                <tr class="border-t"><td class="py-1">Dasar setor sendiri</td><td class="py-1 text-right tabular-nums">{{ $fmt($r['dasar_setor']) }}</td></tr>
                <tr class="font-semibold text-base"><td class="py-1.5">PPh Final harus disetor (0,5%)</td><td class="py-1.5 text-right tabular-nums">{{ $fmt($r['harus_setor']) }}</td></tr>
                <tr><td class="py-1">Sudah disetor</td><td class="py-1 text-right tabular-nums">{{ $fmt($r['sudah_setor']) }}</td></tr>
                <tr class="border-t font-medium {{ $r['selisih'] < 0 ? 'text-red-600' : ($r['selisih'] > 0 ? 'text-blue-700' : '') }}">
                    <td class="py-1">{{ $r['selisih'] < 0 ? 'Kurang setor' : ($r['selisih'] > 0 ? 'Lebih setor' : 'Selisih') }}</td>
                    <td class="py-1 text-right tabular-nums">{{ $fmt(abs($r['selisih'])) }}</td>
                </tr>
            </tbody>
        </table>
        <div class="mt-3 text-xs text-gray-500 space-y-0.5">
            <div>Jatuh tempo setor: <b class="{{ $r['terlambat'] ? 'text-red-600' : '' }}">{{ $r['jatuh_tempo']->translatedFormat('d F Y') }}</b> · kode billing akun 411128, jenis setoran 420.</div>
            <div>Dipungut marketplace di bulan ini (bukti pungut, {{ $r['pungut_bulan_faktur'] }} pesanan selesai): <b>{{ $fmt($r['pungut_bulan']) }}</b>
                @if($r['pungut_bulan_taksiran'] > 0) · <span class="text-amber-700">{{ $fmt($r['pungut_bulan_taksiran']) }} masih taksiran</span>@endif
            </div>
            @if($r['pajak_menunggu'] > 0)
                <div>Faktur masa ini yang pesanannya belum selesai akan dipungut nanti: ≈ <b>{{ $fmt($r['pajak_menunggu']) }}</b></div>
            @endif
        </div>

        {{-- Hutang PPh Final (akun 2114) --}}
        <div class="mt-3 border-t pt-3 text-xs">
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <div class="font-semibold text-gray-700">Hutang PPh Final (akun 2114)</div>
                @if(abs($hutang['selisih']) >= 1)
                    <form method="POST" action="{{ route('accounting.reports.pajak.hutang.bukukan', [$tahun, $bulan]) }}" class="print:hidden">
                        @csrf
                        <button class="border border-blue-600 text-blue-700 rounded px-2 py-1 hover:bg-blue-50">
                            {{ $hutang['dibukukan'] > 0 ? 'Perbarui hutang' : 'Bukukan hutang' }} ({{ $hutang['selisih'] > 0 ? '+' : '−' }}{{ $fmt(abs($hutang['selisih'])) }})
                        </button>
                    </form>
                @endif
            </div>
            <table class="w-full mt-1 text-gray-600">
                <tr><td class="py-0.5">Semestinya dibukukan{{ $hutang['langsung'] > 0 ? ' (harus setor − setoran lama langsung ke 5012 ' . $fmt($hutang['langsung']) . ')' : '' }}</td><td class="text-right tabular-nums">{{ $fmt($hutang['target']) }}</td></tr>
                <tr><td class="py-0.5">Sudah dibukukan (Dr 5012 / Cr 2114)</td><td class="text-right tabular-nums">{{ $fmt($hutang['dibukukan']) }}</td></tr>
            </table>
            @if(abs($hutang['selisih']) >= 1)
                <div class="text-amber-700 mt-1">{{ $r['status'] === 'berjalan' ? 'Masa masih berjalan — hutang otomatis dibukukan tanggal 1 bulan depan, atau saat setoran dicatat.' : 'Angka masa ini berubah sejak terakhir dibukukan.' }}</div>
            @endif
            @foreach($hutangJurnal as $j)
                <div class="text-gray-400">{{ \Carbon\Carbon::parse($j->date)->format('d/m/Y') }} · {{ $j->journal_number }} · {{ $j->description }}</div>
            @endforeach
        </div>
    </div>

    {{-- Setoran --}}
    <div class="bg-white rounded shadow p-4">
        <h2 class="text-sm font-semibold mb-2">Setoran masa ini</h2>
        @forelse($setoran as $s)
            <div class="border rounded p-2 mb-2 text-xs flex justify-between gap-2">
                <div>
                    <div class="font-semibold tabular-nums">{{ rupiah($s->amount) }}</div>
                    <div class="text-gray-500">{{ $s->paid_at->format('d/m/Y') }} · NTPN <span class="font-mono">{{ $s->ntpn ?: '—' }}</span></div>
                    @if(!$s->cash_disbursement_id)
                        <span class="text-gray-400">dibukukan di luar ERP</span>
                    @elseif($s->cashDisbursement)
                        <a href="{{ route('finance.cash-bank.disbursements.show', $s->cash_disbursement_id) }}" class="text-blue-600 hover:underline">{{ $s->cashDisbursement->number }}</a>
                    @endif
                </div>
                <form method="POST" action="{{ route('accounting.reports.pajak.setoran.destroy', $s) }}" class="print:hidden"
                      onsubmit="return confirm('Lepas tanda setoran ini? Pengeluarannya tetap ada.')">
                    @csrf @method('DELETE')
                    <button class="text-gray-400 hover:text-red-600" title="Lepas tanda setoran">✕</button>
                </form>
            </div>
        @empty
            <div class="text-xs text-gray-400 mb-2">Belum ada setoran.</div>
        @endforelse

        <form method="POST" action="{{ route('accounting.reports.pajak.setoran.store') }}" class="border-t pt-3 mt-2 space-y-2 text-sm print:hidden">
            @csrf
            <div class="text-xs font-semibold text-gray-600">Catat setoran</div>
            <input type="hidden" name="period" value="{{ $r['masa']->format('Y-m') }}">
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] text-gray-500">Tanggal setor</label>
                    <input type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}" class="w-full border rounded px-2 py-1" required>
                </div>
                <div>
                    <label class="block text-[11px] text-gray-500">Nominal</label>
                    <input type="text" name="amount" value="{{ old('amount', (int) max(0, -$r['selisih']) ?: '') }}" class="rupiah-input w-full border rounded px-2 py-1 text-right" required>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] text-gray-500">NTPN</label>
                    <input type="text" name="ntpn" value="{{ old('ntpn') }}" class="w-full border rounded px-2 py-1 font-mono" maxlength="40">
                </div>
                <div>
                    <label class="block text-[11px] text-gray-500">Kode billing</label>
                    <input type="text" name="billing_code" value="{{ old('billing_code') }}" class="w-full border rounded px-2 py-1 font-mono" maxlength="40">
                </div>
            </div>
            <div>
                <label class="block text-[11px] text-gray-500">Dibayar dari</label>
                <select name="cash_account_id" class="w-full border rounded px-2 py-1">
                    <option value="">— pilih kas/bank —</option>
                    @foreach($cashAccounts as $a)
                        <option value="{{ $a->id }}" @selected(old('cash_account_id') == $a->id)>{{ $a->code }} · {{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Catatan (opsional)" class="w-full border rounded px-2 py-1">
            <label class="flex items-start gap-1.5 text-[11px] text-gray-600">
                <input type="checkbox" name="tanpa_jurnal" value="1" class="mt-0.5" @checked(old('tanpa_jurnal'))>
                <span>Tanpa jurnal — setoran lama yang sudah dibukukan di luar ERP (kas/bank tidak perlu dipilih).</span>
            </label>
            <button class="w-full bg-blue-600 text-white rounded py-1.5 text-sm">Simpan setoran</button>
            <div class="text-[11px] text-gray-500">Otomatis membukukan hutang masa ini (Dr 5012 / Cr 2114) lalu membuat Pengeluaran: Dr 2114 Hutang PPh Final / Cr kas-bank.</div>
        </form>

        <form method="POST" action="{{ route('accounting.reports.pajak.omzet-luar.store') }}" class="border-t pt-3 mt-3 space-y-2 text-sm print:hidden">
            @csrf
            <input type="hidden" name="period" value="{{ $r['masa']->format('Y-m') }}">
            <div class="text-xs font-semibold text-gray-600">Omzet di luar ERP</div>
            <div class="text-[11px] text-gray-500">Penjualan masa ini yang tidak ada fakturnya di ERP (mis. Jan–Mei sebelum ERP dipakai). Dihitung sebagai omzet setor sendiri. Isi 0 untuk menghapus.</div>
            <div class="grid grid-cols-2 gap-2">
                <input type="text" name="amount" value="{{ $omzetLuar ? (int) $omzetLuar->amount : '' }}" placeholder="Nominal" class="rupiah-input border rounded px-2 py-1 text-right">
                <input type="text" name="notes" value="{{ $omzetLuar?->notes }}" placeholder="Sumber (mis. rekap Excel)" class="border rounded px-2 py-1">
            </div>
            <button class="w-full border border-gray-400 text-gray-700 rounded py-1 text-xs hover:bg-gray-50">Simpan omzet di luar ERP</button>
        </form>
    </div>
</div>

{{-- Per kanal --}}
<div class="bg-white rounded shadow overflow-x-auto mb-4">
    <div class="px-4 pt-3 text-sm font-semibold">Per kanal penjualan</div>
    <table class="w-full text-sm mt-2">
        <thead class="bg-gray-50 text-xs text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Kanal</th>
                <th class="px-3 py-2 text-right">Faktur</th>
                <th class="px-3 py-2 text-right">Omzet</th>
                <th class="px-3 py-2 text-right">Retur</th>
                <th class="px-3 py-2 text-right">Dipungut</th>
                <th class="px-3 py-2 text-right">Menunggu</th>
                <th class="px-3 py-2 text-right">Setor sendiri</th>
                <th class="px-3 py-2 text-right">Pajak MP<br><span class="font-normal">dipungut + menunggu</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse($kanal as $key => $k)
                <tr class="border-t">
                    <td class="px-3 py-2"><a href="{{ request()->fullUrlWithQuery(['kanal' => $key, 'page' => null]) }}#faktur" class="text-blue-600 hover:underline">{{ $k['label'] }}</a></td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $k['faktur'] }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($k['omzet']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $k['retur'] ? $fmt(-$k['retur']) : '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $k['dipungut'] ? $fmt($k['dipungut']) : '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $k['menunggu'] ? $fmt($k['menunggu']) : '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $k['sendiri'] ? $fmt($k['sendiri']) : '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $k['pajak_dipungut'] ? $fmt($k['pajak_dipungut']) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-4 text-center text-xs text-gray-400">Belum ada faktur di masa ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Transisi --}}
@if($transisi->isNotEmpty())
    <div class="bg-white rounded shadow p-4 mb-4">
        <h2 class="text-sm font-semibold">Faktur bulan sebelumnya yang baru selesai (dan dipungut) di masa ini</h2>
        <div class="text-xs text-gray-500 mb-2">
            Pesanannya masuk sejak tanggal mulai pungut, omzetnya tercatat di masa faktur, dan marketplace memotong pajaknya saat pesanan selesai di {{ $masaLabel }}.
            Di laporan masa asalnya faktur ini sudah dihitung "dipungut" (tidak disetor sendiri).
        </div>
        <table class="w-full text-xs">
            <thead class="text-gray-500"><tr>
                <th class="text-left py-1">Faktur</th><th class="text-left py-1">Tgl faktur</th><th class="text-left py-1">Selesai</th>
                <th class="text-left py-1">Kanal</th><th class="text-right py-1">Omzet</th><th class="text-right py-1">Pajak</th>
            </tr></thead>
            <tbody>
                @foreach($transisi as $x)
                    <tr class="border-t">
                        <td class="py-1"><a href="{{ route('sales.invoices.show', $x->id) }}" class="text-blue-600 hover:underline">{{ $x->nomor }}</a></td>
                        <td class="py-1">{{ \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') }}</td>
                        <td class="py-1">{{ \Carbon\Carbon::parse($x->selesai_at)->format('d/m/Y') }}</td>
                        <td class="py-1">{{ $x->kanal_label }}</td>
                        <td class="py-1 text-right tabular-nums">{{ $fmt($x->omzet) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ $fmt($x->pajak) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t font-semibold"><tr>
                <td colspan="4" class="py-1">{{ $transisi->count() }} faktur</td>
                <td class="py-1 text-right tabular-nums">{{ $fmt($transisi->sum('omzet')) }}</td>
                <td class="py-1 text-right tabular-nums">{{ $fmt($transisi->sum('pajak')) }}</td>
            </tr></tfoot>
        </table>
    </div>
@endif

{{-- Daftar faktur --}}
<div id="faktur" class="bg-white rounded shadow mb-4">
    <div class="px-3 pt-3 flex flex-wrap gap-2 text-xs print:hidden">
        <span class="text-gray-500 self-center">Pajak per faktur:</span>
        @foreach(['lunas', 'sebagian', 'belum', 'bebas', 'dipotong_mp', 'menunggu_mp'] as $k)
            @continue(!isset($bayar[$k]))
            <a href="{{ request()->fullUrlWithQuery(['bayar' => $k === 'sebagian' ? 'sebagian' : $k, 'status' => null, 'page' => null]) }}#faktur"
               class="px-2 py-1 rounded {{ $bayarCls[$k] }} {{ request('bayar') === $k ? 'ring-2 ring-offset-1 ring-blue-400' : '' }}">
                {{ $bayarLabels[$k] }}: {{ $bayar[$k]['faktur'] }} faktur · {{ $fmt($bayar[$k]['pajak']) }}
            </a>
        @endforeach
        @if(($bayar['_luar']['pajak'] ?? 0) > 0)
            <span class="px-2 py-1 rounded bg-gray-50 text-gray-600">+ omzet di luar ERP: pajak {{ $fmt($bayar['_luar']['pajak']) }} (ditutup setoran lebih dulu)</span>
        @endif
        @if(($bayar['_retur']['pajak'] ?? 0) > 0)
            <span class="px-2 py-1 rounded bg-gray-50 text-gray-600">− retur: {{ $fmt($bayar['_retur']['pajak']) }}</span>
        @endif
    </div>
    <form method="GET" class="p-3 flex gap-3 items-end text-sm flex-wrap border-b print:hidden">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Status</label>
            <select name="status" class="filter-auto border rounded px-2 py-1.5">
                <option value="">Semua</option>
                @foreach($statusLabels as $k => $l)
                    <option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Kanal</label>
            <select name="kanal" class="filter-auto border rounded px-2 py-1.5">
                <option value="">Semua</option>
                @foreach($kanal as $key => $k)
                    <option value="{{ $key }}" @selected(request('kanal') === $key)>{{ $k['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Pembayaran pajak</label>
            <select name="bayar" class="filter-auto border rounded px-2 py-1.5">
                <option value="">Semua</option>
                <option value="lunas" @selected(request('bayar') === 'lunas')>Sudah disetor</option>
                <option value="belum" @selected(request('bayar') === 'belum')>Belum disetor (termasuk sebagian)</option>
                <option value="sebagian" @selected(request('bayar') === 'sebagian')>Disetor sebagian</option>
                <option value="bebas" @selected(request('bayar') === 'bebas')>Bebas (jatah Rp500 jt)</option>
                <option value="dipotong_mp" @selected(request('bayar') === 'dipotong_mp')>Dipotong marketplace</option>
                <option value="menunggu_mp" @selected(request('bayar') === 'menunggu_mp')>Dipotong saat selesai</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Cari</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="No. faktur / pelanggan" class="search-live border rounded px-2 py-1.5">
        </div>
        @include('erp._partials.per-page-select')
        <div class="ml-auto text-xs text-gray-500">{{ $fakturPage->total() }} faktur · omzet {{ $fmt($fakturFilterTotal) }} · pajak {{ $fmt($fakturFilterPajak) }}</div>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left">Tanggal</th>
                    <th class="px-3 py-2 text-left">Faktur</th>
                    <th class="px-3 py-2 text-left">Pelanggan</th>
                    <th class="px-3 py-2 text-left">Kanal</th>
                    <th class="px-3 py-2 text-right">Omzet</th>
                    <th class="px-3 py-2 text-left">Status pajak</th>
                    <th class="px-3 py-2 text-left">Pesanan masuk<br><span class="font-normal">/ selesai MP</span></th>
                    <th class="px-3 py-2 text-right">PPh 0,5%</th>
                    <th class="px-3 py-2 text-left">Pembayaran</th>
                </tr>
            </thead>
            <tbody>
                @forelse($fakturPage as $x)
                    <tr class="border-t">
                        <td class="px-3 py-1.5 text-xs">{{ \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') }}</td>
                        <td class="px-3 py-1.5"><a href="{{ route('sales.invoices.show', $x->id) }}" class="text-blue-600 hover:underline">{{ $x->nomor }}</a></td>
                        <td class="px-3 py-1.5 text-xs">{{ $x->pelanggan }}</td>
                        <td class="px-3 py-1.5 text-xs">{{ $x->kanal_label }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $fmt($x->omzet) }}</td>
                        <td class="px-3 py-1.5"><span class="px-1.5 py-0.5 rounded text-xs {{ $statusCls[$x->status] }}">{{ $x->status_label }}</span></td>
                        <td class="px-3 py-1.5 text-xs">
                            @if($x->sales_order_id && $x->is_marketplace)
                                {{ \Carbon\Carbon::parse($x->pesanan_at)->format('d/m/Y') }}
                                <div class="text-gray-400">{{ $x->selesai_at ? 'selesai ' . \Carbon\Carbon::parse($x->selesai_at)->format('d/m/Y') : 'belum selesai' }}</div>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-1.5 text-right tabular-nums">
                            @if($x->pajak_faktur > 0)
                                {{ $fmt($x->pajak_faktur) }}
                                @if($x->status !== 'sendiri')
                                    <div class="text-[10px] {{ $x->sumber_pajak === 'taksiran' ? 'text-amber-700' : 'text-gray-400' }}">{{ $sumberLabel[$x->sumber_pajak] }}</div>
                                @elseif($x->bebas > 0)
                                    <div class="text-[10px] text-gray-400">sebagian bebas</div>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-1.5"><span class="px-1.5 py-0.5 rounded text-xs whitespace-nowrap {{ $bayarCls[$x->bayar] }}">{{ $x->bayar_label }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-4 text-center text-xs text-gray-400">Tidak ada faktur.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3 print:hidden">{{ $fakturPage->links() }}</div>
</div>

@if($retur->isNotEmpty())
    <div class="bg-white rounded shadow p-4">
        <h2 class="text-sm font-semibold mb-2">Retur masa ini</h2>
        <table class="w-full text-xs">
            <thead class="text-gray-500"><tr>
                <th class="text-left py-1">Tanggal</th><th class="text-left py-1">Retur</th><th class="text-left py-1">Faktur</th>
                <th class="text-left py-1">Kanal</th><th class="text-left py-1">Status faktur</th><th class="text-right py-1">Nilai dibalik</th>
            </tr></thead>
            <tbody>
                @foreach($retur as $x)
                    <tr class="border-t">
                        <td class="py-1">{{ \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') }}</td>
                        <td class="py-1">{{ $x->nomor }}</td>
                        <td class="py-1"><a href="{{ route('sales.invoices.show', $x->invoice_id) }}" class="text-blue-600 hover:underline">{{ $x->faktur_nomor }}</a></td>
                        <td class="py-1">{{ $x->kanal_label }}</td>
                        <td class="py-1">{{ $statusLabels[$x->status] }}</td>
                        <td class="py-1 text-right tabular-nums">{{ $fmt($x->nilai) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@include('erp.purchasing._partials.list-scripts')
@endsection
