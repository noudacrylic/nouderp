@extends('layouts.erp')

@php
    $fmt = fn ($n) => $n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.');
    $badge = fn ($s) => match ($s) {
        'lunas'    => ['Lunas', 'bg-green-100 text-green-700'],
        'lebih'    => ['Lebih bayar', 'bg-blue-100 text-blue-700'],
        'kurang'   => ['Kurang', 'bg-amber-100 text-amber-800'],
        'belum'    => ['Belum setor', 'bg-red-100 text-red-700'],
        'berjalan' => ['Masa berjalan', 'bg-gray-100 text-gray-600'],
        default    => ['Nihil', 'bg-gray-100 text-gray-500'],
    };
    $t = $rekap['total'];
    $p = $rekap['proyeksi'];
    $persenKumulatif = min(100, $rekap['kumulatif'] / \App\Modules\Finance\Services\PphFinalReportService::BATAS_OMZET * 100);
@endphp

@section('content')
<div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
    <div>
        <h1 class="text-lg font-semibold">Laporan Pajak · PPh Final UMKM 0,5%</h1>
        <div class="text-xs text-gray-500 mt-0.5">
            Omzet menurut tanggal faktur (tanpa ongkir), dikurangi retur. Pesanan marketplace yang MASUK sejak 2 Okt 2026 dipotong PPh 22 oleh marketplace saat pesanan selesai, jadi tidak disetor sendiri. Pesanan yang masuk sebelumnya tetap disetor sendiri walau selesai di Oktober. Pajak dipungut ditampilkan di bulan pesanan selesai. Klik angka untuk melihat fakturnya.
        </div>
    </div>
    <form method="GET" class="flex items-end gap-2 print:hidden">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tahun</label>
            <select name="tahun" class="filter-auto border rounded px-2 py-1.5 text-sm">
                @for($y = now()->year; $y >= 2026; $y--)
                    <option value="{{ $y }}" @selected($y === $tahun)>{{ $y }}</option>
                @endfor
            </select>
        </div>
    </form>
</div>

@foreach($peringatan as $msg)
    <div class="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
        ⚠ {{ $msg }} <a href="{{ route('settings.jubelio.edit') }}" class="underline">Atur</a>
    </div>
@endforeach

{{-- Kartu ringkas --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <div class="bg-white rounded shadow p-3">
        <div class="text-xs text-gray-500">Omzet neto {{ $tahun }}</div>
        <div class="text-lg font-semibold tabular-nums">{{ $fmt($t['omzet_neto']) }}</div>
        <div class="mt-2 h-1.5 bg-gray-100 rounded">
            <div class="h-1.5 rounded {{ $persenKumulatif >= 80 ? 'bg-red-500' : ($persenKumulatif >= 60 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $persenKumulatif }}%"></div>
        </div>
        <div class="text-[11px] text-gray-500 mt-1">{{ number_format($persenKumulatif, 1, ',', '.') }}% dari batas Rp4,8 M</div>
    </div>
    <div class="bg-white rounded shadow p-3">
        <div class="text-xs text-gray-500">Proyeksi omzet setahun</div>
        <div class="text-lg font-semibold tabular-nums">{{ $fmt($p['setahun']) }}</div>
        <div class="text-[11px] text-gray-500 mt-1">
            @if($p['bulan_lewat'] > 0 && $p['bulan_lewat'] < 12)
                rata-rata {{ $fmt($p['rata_bulanan']) }}/bln × sisa bulan
            @elseif($p['bulan_lewat'] >= 12)
                tahun penuh
            @else
                belum ada bulan yang lewat
            @endif
        </div>
    </div>
    <div class="bg-white rounded shadow p-3">
        <div class="text-xs text-gray-500">Dipungut marketplace {{ $tahun }}</div>
        <div class="text-lg font-semibold tabular-nums">{{ $fmt($t['pungut_bulan']) }}</div>
        @if($t['pajak_menunggu'] > 0)
            <div class="text-[11px] text-gray-500 mt-1">+ {{ $fmt($t['pajak_menunggu']) }} menunggu pesanan selesai</div>
        @endif
    </div>
    <div class="bg-white rounded shadow p-3">
        <div class="text-xs text-gray-500">Setor sendiri: harus / sudah</div>
        <div class="text-lg font-semibold tabular-nums">{{ $fmt($t['harus_setor']) }} <span class="text-gray-400 text-sm">/ {{ $fmt($t['sudah_setor']) }}</span></div>
        @php $kurang = $t['harus_setor'] - $t['sudah_setor']; @endphp
        <div class="text-[11px] mt-1 {{ $kurang > 0 ? 'text-red-600' : 'text-gray-500' }}">
            {{ $kurang > 0 ? 'kurang ' . $fmt($kurang) : ($kurang < 0 ? 'lebih ' . $fmt(-$kurang) : 'seimbang') }}
        </div>
        <div class="text-[11px] text-gray-500 mt-1 border-t pt-1" title="Saldo akun 2114 = pajak setor sendiri yang sudah dibukukan tapi belum disetor (semua masa)">
            Saldo Hutang PPh Final (2114): <b class="tabular-nums {{ $saldoHutang > 0 ? 'text-red-600' : '' }}">{{ $fmt($saldoHutang) }}</b>
        </div>
    </div>
</div>

@if($p['setahun'] >= \App\Modules\Finance\Services\PphFinalReportService::BATAS_OMZET * 0.8)
    <div class="mb-4 rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-800">
        ⚠ Proyeksi omzet {{ $tahun }} mendekati/melewati Rp4,8 M. Bila terlampaui: tarif 0,5% masih berlaku sampai akhir tahun ini, tahun berikutnya wajib PPh reguler, dan wajib daftar PKP paling lambat akhir bulan berikutnya setelah batas terlewati.
    </div>
@endif

{{-- Rekap per masa --}}
<div class="bg-white rounded shadow overflow-x-auto mb-4">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Masa</th>
                <th class="px-3 py-2 text-right">Omzet neto</th>
                <th class="px-3 py-2 text-right">Dipungut MP<br><span class="font-normal">omzet</span></th>
                <th class="px-3 py-2 text-right">Setor sendiri<br><span class="font-normal">omzet</span></th>
                <th class="px-3 py-2 text-right" title="Menurut bulan pesanan SELESAI — sama dengan bukti pungut marketplace">Pajak dipungut MP<br><span class="font-normal">bulan dipungut</span></th>
                <th class="px-3 py-2 text-right">Harus setor</th>
                <th class="px-3 py-2 text-right">Sudah setor</th>
                <th class="px-3 py-2 text-right">Belum setor</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-left">Jatuh tempo</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rekap['bulan'] as $m => $b)
                @php
                    [$lbl, $cls] = $badge($b['status']);
                    $ke = fn (array $q) => route('accounting.reports.pajak.masa', [$tahun, $m] + $q) . '#faktur';
                @endphp
                <tr class="border-t hover:bg-gray-50 {{ $b['faktur_count'] === 0 && $b['sudah_setor'] == 0 ? 'text-gray-400' : '' }}">
                    <td class="px-3 py-2">
                        <a href="{{ route('accounting.reports.pajak.masa', [$tahun, $m]) }}" class="text-blue-600 hover:underline">{{ $b['masa']->translatedFormat('F') }}</a>
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ $fmt($b['omzet_neto']) }}
                        @if($b['omzet_luar'] != 0)<div class="text-[11px] text-gray-500">luar ERP {{ $fmt($b['omzet_luar']) }}</div>@endif
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums"><a href="{{ $ke(['status' => 'dipungut']) }}" class="hover:underline hover:text-blue-600">{{ $fmt($b['omzet_dipungut']) }}</a></td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        <a href="{{ $ke(['status' => 'sendiri']) }}" class="hover:underline hover:text-blue-600">{{ $fmt($b['omzet_sendiri']) }}</a>
                        @if($b['omzet_bebas'] > 0)
                            <div class="text-[11px] text-emerald-700">bebas {{ $fmt($b['omzet_bebas']) }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ $fmt($b['pungut_bulan']) }}
                        @if($b['pungut_bulan_taksiran'] > 0)
                            <div class="text-[11px] text-amber-700" title="Sebagian masih taksiran (belum ada angka asli dari marketplace / jurnal settlement)">≈ taksiran {{ $fmt($b['pungut_bulan_taksiran']) }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums font-medium">
                        <a href="{{ $ke(['status' => 'sendiri']) }}" class="hover:underline hover:text-blue-600">{{ $fmt($b['harus_setor']) }}</a>
                        @if($b['hutang_selisih'] != 0 && $b['harus_setor'] > 0)
                            <div class="text-[11px] font-normal text-amber-700" title="Belum dibukukan ke akun 2114 Hutang PPh Final">hutang belum dibukukan {{ $fmt($b['hutang_selisih']) }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        @if($b['sudah_setor'] > 0)
                            <a href="{{ $ke(['bayar' => 'lunas']) }}" class="hover:underline hover:text-blue-600">{{ $fmt($b['sudah_setor']) }}</a>
                        @else
                            {{ $fmt(0) }}
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums {{ $b['belum_setor'] > 0 ? 'text-red-600' : '' }}">
                        @if($b['belum_setor'] > 0)
                            <a href="{{ $ke(['bayar' => 'belum']) }}" class="hover:underline">{{ $fmt($b['belum_setor']) }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2"><span class="px-1.5 py-0.5 rounded text-xs {{ $cls }}">{{ $lbl }}</span></td>
                    <td class="px-3 py-2 text-xs {{ $b['terlambat'] ? 'text-red-600 font-semibold' : 'text-gray-500' }}">
                        {{ $b['jatuh_tempo']->format('d/m/Y') }}{{ $b['terlambat'] ? ' · lewat' : '' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="border-t-2 font-semibold bg-gray-50">
            <tr>
                <td class="px-3 py-2">Total</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($t['omzet_neto']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($t['omzet_dipungut']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($t['omzet_sendiri']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($t['pungut_bulan']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($t['harus_setor']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($t['sudah_setor']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt(array_sum(array_column($rekap['bulan'], 'belum_setor'))) }}</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- Uang muka diterima, belum difaktur --}}
@if($dpBelumFaktur->isNotEmpty())
<div class="bg-white rounded shadow p-4 mb-4">
    <h2 class="text-sm font-semibold">Uang muka sudah diterima, pesanan belum difaktur ({{ $dpBelumFaktur->count() }})</h2>
    <div class="text-xs text-gray-500 mb-2">
        Laporan ini menghitung omzet menurut <b>tanggal faktur</b>, jadi pesanan di bawah belum masuk masa mana pun —
        omzetnya baru dihitung penuh di bulan fakturnya terbit. Pastikan segera difaktur saat barang diserahkan supaya tak terlewat.
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="text-gray-500"><tr>
                <th class="text-left py-1">SO</th><th class="text-left py-1">Pelanggan</th><th class="text-left py-1">Tgl SO</th>
                <th class="text-left py-1">Uang muka diterima</th><th class="text-right py-1">Nilai SO</th><th class="text-right py-1">Uang muka</th>
                <th class="text-right py-1">PPh 0,5% atas nilai SO</th>
            </tr></thead>
            <tbody>
                @foreach($dpBelumFaktur as $x)
                    <tr class="border-t">
                        <td class="py-1"><a href="{{ route('sales.orders.show', $x->id) }}" class="text-blue-600 hover:underline">{{ $x->order_number }}</a></td>
                        <td class="py-1">{{ $x->pelanggan }}</td>
                        <td class="py-1">{{ \Carbon\Carbon::parse($x->order_date)->format('d/m/Y') }}</td>
                        <td class="py-1 {{ \Carbon\Carbon::parse($x->dp_pertama)->lt(now()->startOfMonth()) ? 'text-amber-700 font-medium' : '' }}">
                            {{ \Carbon\Carbon::parse($x->dp_pertama)->format('d/m/Y') }}{{ $x->dp_terakhir !== $x->dp_pertama ? ' – ' . \Carbon\Carbon::parse($x->dp_terakhir)->format('d/m/Y') : '' }}
                        </td>
                        <td class="py-1 text-right tabular-nums">{{ $fmt($x->grand_total) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ $fmt($x->dp) }}</td>
                        <td class="py-1 text-right tabular-nums">≈ {{ $fmt(floor($x->grand_total * 0.005)) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t font-semibold"><tr>
                <td colspan="4" class="py-1">Total</td>
                <td class="py-1 text-right tabular-nums">{{ $fmt($dpBelumFaktur->sum('grand_total')) }}</td>
                <td class="py-1 text-right tabular-nums">{{ $fmt($dpBelumFaktur->sum('dp')) }}</td>
                <td class="py-1 text-right tabular-nums">≈ {{ $fmt(floor($dpBelumFaktur->sum('grand_total') * 0.005)) }}</td>
            </tr></tfoot>
        </table>
    </div>
</div>
@endif

{{-- Setoran tercatat --}}
<div class="grid lg:grid-cols-2 gap-4">
    <div class="bg-white rounded shadow p-4">
        <h2 class="text-sm font-semibold mb-2">Setoran tercatat {{ $tahun }}</h2>
        @if($setoran->isEmpty())
            <div class="text-xs text-gray-400 py-2">Belum ada setoran yang ditandai. Catat setoran dari halaman rincian masa.</div>
        @else
            <table class="w-full text-xs">
                <thead class="text-gray-500"><tr>
                    <th class="text-left py-1">Masa</th><th class="text-left py-1">Tgl setor</th>
                    <th class="text-left py-1">NTPN</th><th class="text-left py-1">Pengeluaran</th><th class="text-right py-1">Nominal</th>
                </tr></thead>
                <tbody>
                    @foreach($setoran as $s)
                        <tr class="border-t">
                            <td class="py-1">{{ $s->period->translatedFormat('M Y') }}</td>
                            <td class="py-1">{{ $s->paid_at->format('d/m/Y') }}</td>
                            <td class="py-1 font-mono">{{ $s->ntpn ?: '—' }}</td>
                            <td class="py-1">{{ $s->cashDisbursement->number ?? ($s->cash_disbursement_id ? '—' : 'di luar ERP') }}</td>
                            <td class="py-1 text-right tabular-nums">{{ $fmt($s->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="bg-white rounded shadow p-4">
        <h2 class="text-sm font-semibold">Pengeluaran ke akun 5012 yang belum ditandai</h2>
        <div class="text-xs text-gray-500 mb-2">Setoran yang dulu dicatat manual. Tandai masa pajaknya supaya terhitung sebagai "sudah setor".</div>
        @if($setoranLama->isEmpty())
            <div class="text-xs text-gray-400 py-2">Tidak ada.</div>
        @else
            <div class="space-y-2">
                @foreach($setoranLama as $cd)
                    @php $tebakan = \Carbon\Carbon::parse($cd->date)->subMonthNoOverflow()->format('Y-m'); @endphp
                    <form method="POST" action="{{ route('accounting.reports.pajak.setoran.tandai') }}" class="border rounded p-2 flex flex-wrap items-end gap-2 text-xs">
                        @csrf
                        <input type="hidden" name="cash_disbursement_id" value="{{ $cd->id }}">
                        <div class="min-w-[160px] flex-1">
                            <div class="font-semibold">{{ $cd->number }} · {{ \Carbon\Carbon::parse($cd->date)->format('d/m/Y') }}</div>
                            <div class="text-gray-500 truncate" title="{{ $cd->uraian }}">{{ $cd->uraian ?: ($cd->notes ?: $cd->payee) }}</div>
                            <div class="font-medium tabular-nums">{{ rupiah($cd->jumlah) }}</div>
                        </div>
                        <div>
                            <label class="block text-[11px] text-gray-500">Masa</label>
                            <input type="month" name="period" value="{{ $tebakan }}" class="border rounded px-1.5 py-1" required>
                        </div>
                        <div>
                            <label class="block text-[11px] text-gray-500">NTPN</label>
                            <input type="text" name="ntpn" class="border rounded px-1.5 py-1 w-36" placeholder="opsional">
                        </div>
                        <button class="border border-blue-600 text-blue-700 rounded px-2 py-1 hover:bg-blue-50">Tandai</button>
                    </form>
                @endforeach
            </div>
        @endif
    </div>
</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
