@extends('layouts.erp')

@php
    $rp = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    $roasTeks = fn ($r) => $r === null ? '—' : number_format($r, 1, ',', '.');
    $roasAcuan = $total['roas'] ?: 0;
    // Warna ROAS relatif terhadap ROAS rata-rata rentang ini: < separuh = merah, > 1,2× = hijau.
    $kelasRoas = function ($r) use ($roasAcuan) {
        if ($r === null || !$roasAcuan) return 'bg-gray-300';
        if ($r < $roasAcuan * 0.5) return 'bg-red-400';
        if ($r > $roasAcuan * 1.2) return 'bg-green-500';
        return 'bg-amber-400';
    };
    $teksRoas = function ($r) use ($roasAcuan) {
        if ($r === null || !$roasAcuan) return 'text-gray-400';
        if ($r < $roasAcuan * 0.5) return 'text-red-600';
        if ($r > $roasAcuan * 1.2) return 'text-green-700';
        return 'text-amber-700';
    };
    $maksJam = max(1, max(array_column($petaJam, 'biaya_per_hari')));
    $jumlahHari = max(1, count(array_filter($harian, fn ($h) => $h['tanggal']->lte($sampai))));
    $hariIni = collect($harian)->first(fn ($h) => $h['tanggal']->isToday());
@endphp

@section('content')
<div class="flex items-center justify-between mb-1 gap-2 flex-wrap">
    <h1 class="text-lg font-semibold">Iklan Shopee</h1>
    <div class="flex items-center gap-2 flex-wrap">
        <form method="GET" class="flex items-center gap-1">
            @foreach (['status' => $status, 'cari' => $cari] as $k => $v)
                @if ($v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
            @endforeach
            <select name="hari" onchange="this.form.submit()" class="border rounded px-2 py-1.5 text-sm">
                @foreach (\App\Modules\Marketplace\Shopee\Controllers\IklanShopeeController::RENTANG as $h)
                    <option value="{{ $h }}" @selected($hari === $h)>{{ $h }} hari terakhir</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('sales.iklan-shopee.kelompok.index') }}" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">Kelompok &amp; Jadwal</a>
        <form method="POST" action="{{ route('sales.iklan-shopee.tarik') }}">@csrf
            <button class="border border-gray-300 text-gray-700 px-3 py-1.5 rounded text-sm hover:bg-gray-50">↻ Tarik sekarang</button>
        </form>
    </div>
</div>
<p class="text-xs text-gray-500 mb-3">
    Data dari Ads API Shopee, ditarik otomatis tiap jam. Halaman ini <b>hanya membaca</b>, belum ada yang mengubah iklan di Shopee.
    Rentang {{ $dari->translatedFormat('d M') }} – {{ $sampai->translatedFormat('d M Y') }} (hari penuh; hari ini terpisah).
    Pesanan &amp; omzet memakai atribusi luas Shopee (angka utama Seller Centre).
    @if ($tarik)
        · Terakhir ditarik {{ \Illuminate\Support\Carbon::parse($tarik['waktu'])->translatedFormat('d M H:i') }}
        @if (!empty($tarik['galat']))<span class="text-red-600">— sebagian gagal: {{ implode('; ', $tarik['galat']) }}</span>@endif
    @else
        · <span class="text-amber-700">Belum pernah ditarik.</span>
    @endif
</p>

@if (!$shop)
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded p-3 text-sm mb-3">Toko Shopee belum terhubung — hubungkan dulu di Pengaturan › Integrasi › Shopee.</div>
@endif

{{-- Ringkasan --}}
<div class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-3 text-sm">
    <div class="bg-white rounded shadow p-3">
        <div class="text-xs text-gray-500">Saldo iklan</div>
        <div class="text-base font-semibold">{{ $saldo ? $rp($saldo['saldo']) : '—' }}</div>
    </div>
    <div class="bg-white rounded shadow p-3">
        <div class="text-xs text-gray-500">Belanja rata-rata / hari</div>
        <div class="text-base font-semibold">{{ $rp($total['biaya'] / $jumlahHari) }}</div>
    </div>
    <div class="bg-white rounded shadow p-3">
        <div class="text-xs text-gray-500">ROAS rentang ini</div>
        <div class="text-base font-semibold">{{ $roasTeks($total['roas']) }}</div>
    </div>
    <div class="bg-white rounded shadow p-3">
        <div class="text-xs text-gray-500">Pesanan dari iklan</div>
        <div class="text-base font-semibold">{{ number_format($total['pesanan'], 0, ',', '.') }}</div>
    </div>
    <div class="bg-white rounded shadow p-3">
        <div class="text-xs text-gray-500">Hari ini (sampai jam ini)</div>
        <div class="text-base font-semibold">{{ $hariIni ? $rp($hariIni['biaya']) : '—' }}</div>
        @if ($hariIni)<div class="text-xs text-gray-500">{{ $hariIni['pesanan'] }} pesanan · ROAS {{ $roasTeks($hariIni['roas']) }}</div>@endif
    </div>
</div>

{{-- Peta jam --}}
<div class="bg-white rounded shadow p-3 mb-3">
    <div class="flex items-center justify-between gap-2 flex-wrap mb-2">
        <div class="font-semibold text-sm">Belanja &amp; ROAS per jam <span class="font-normal text-xs text-gray-500">(semua iklan, rata-rata per hari)</span></div>
        <div class="flex items-center gap-3 text-xs text-gray-600">
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-green-500"></span>ROAS tinggi</span>
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-amber-400"></span>sedang</span>
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-red-400"></span>rendah (&lt; ½ rata-rata)</span>
        </div>
    </div>
    <div class="overflow-x-auto">
        <div class="flex items-end gap-1 min-w-[640px] h-40 border-b border-gray-200">
            @foreach ($petaJam as $j)
                <div class="flex-1 flex flex-col items-center justify-end h-full"
                     title="Jam {{ sprintf('%02d', $j['jam']) }}: {{ $rp($j['biaya_per_hari']) }}/hari · {{ $j['pesanan'] }} pesanan · ROAS {{ $roasTeks($j['roas']) }}">
                    <div class="text-[10px] text-gray-500 mb-0.5">{{ $j['biaya_per_hari'] >= 1000 ? number_format($j['biaya_per_hari'] / 1000, 0, ',', '.') . 'rb' : '' }}</div>
                    <div class="w-full rounded-t {{ $kelasRoas($j['roas']) }}" style="height: {{ max(2, round($j['biaya_per_hari'] / $maksJam * 100)) }}%"></div>
                </div>
            @endforeach
        </div>
        <div class="flex gap-1 min-w-[640px] text-[10px] text-center text-gray-600 mt-1">
            @foreach ($petaJam as $j)
                <div class="flex-1">{{ sprintf('%02d', $j['jam']) }}</div>
            @endforeach
        </div>
        <div class="flex gap-1 min-w-[640px] text-[10px] text-center mt-0.5">
            @foreach ($petaJam as $j)
                <div class="flex-1 font-semibold {{ $teksRoas($j['roas']) }}">{{ $roasTeks($j['roas']) }}</div>
            @endforeach
        </div>
    </div>
    <p class="text-xs text-gray-500 mt-2">Tinggi batang = belanja rata-rata per hari di jam itu; angka bawah = ROAS. Batang tinggi tapi merah = uang banyak keluar di jam yang kurang menghasilkan.</p>
    <details class="mt-2 text-sm">
        <summary class="cursor-pointer text-xs text-blue-700">Lihat tabel per jam</summary>
        <div class="overflow-x-auto mt-2">
            <table class="w-full text-xs">
                <thead class="text-gray-500 border-b"><tr>
                    <th class="text-left py-1">Jam</th><th class="text-right">Klik</th><th class="text-right">Belanja</th><th class="text-right">/hari</th>
                    <th class="text-right">Pesanan</th><th class="text-right">Omzet</th><th class="text-right">ROAS</th>
                </tr></thead>
                <tbody>
                @foreach ($petaJam as $j)
                    <tr class="border-b border-gray-100">
                        <td class="py-1">{{ sprintf('%02d.00', $j['jam']) }}</td>
                        <td class="text-right">{{ number_format($j['klik'], 0, ',', '.') }}</td>
                        <td class="text-right">{{ $rp($j['biaya']) }}</td>
                        <td class="text-right">{{ $rp($j['biaya_per_hari']) }}</td>
                        <td class="text-right">{{ $j['pesanan'] }}</td>
                        <td class="text-right">{{ $rp($j['gmv']) }}</td>
                        <td class="text-right font-semibold {{ $teksRoas($j['roas']) }}">{{ $roasTeks($j['roas']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>

{{-- Per iklan --}}
<div class="bg-white rounded shadow mb-3">
    <div class="p-3 border-b flex items-end gap-2 flex-wrap text-sm">
        <div class="font-semibold mr-auto">Per iklan</div>
        <form method="GET" class="flex items-end gap-2 flex-wrap">
            <input type="hidden" name="hari" value="{{ $hari }}">
            <select name="status" class="border rounded px-2 py-1.5">
                <option value="hidup" @selected($status === 'hidup')>Berjalan + dijeda ({{ ($hitungStatus['ongoing'] ?? 0) + ($hitungStatus['paused'] ?? 0) + ($hitungStatus['scheduled'] ?? 0) }})</option>
                @foreach (\App\Modules\Marketplace\Shopee\Models\ShopeeIklan::LABEL_STATUS as $k => $l)
                    @if (!empty($hitungStatus[$k]))<option value="{{ $k }}" @selected($status === $k)>{{ $l }} ({{ $hitungStatus[$k] }})</option>@endif
                @endforeach
                <option value="semua" @selected($status === 'semua')>Semua</option>
            </select>
            <input type="text" name="cari" value="{{ $cari }}" placeholder="cari nama iklan…" class="border rounded px-2 py-1.5 w-52">
            <button class="bg-gray-700 text-white px-3 py-1.5 rounded">Filter</button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50 text-gray-600"><tr>
                <th class="text-left px-3 py-2">Iklan</th>
                <th class="text-left px-2">Status</th>
                <th class="text-right px-2">Modal/hari</th>
                <th class="text-right px-2" title="Belanja rata-rata per hari dalam rentang">Belanja/hari</th>
                <th class="text-right px-2">Hari ini</th>
                <th class="text-right px-2">Pesanan</th>
                <th class="text-right px-2">Omzet</th>
                <th class="text-right px-2">ROAS</th>
                <th class="text-left px-2" title="Median jam belanja harian mencapai 95% modal">Modal habis</th>
                <th class="text-right px-3">Stok</th>
            </tr></thead>
            <tbody>
            @forelse ($perIklan as $r)
                @php
                    $i = $r['iklan'];
                    $stokItem = collect($i->item_ids ?? [])->map(fn ($id) => $stok[$id] ?? null)->filter(fn ($s) => $s !== null);
                    $stokTotal = $stokItem->isEmpty() ? null : $stokItem->sum();
                @endphp
                <tr class="border-b border-gray-100 hover:bg-gray-50">
                    <td class="px-3 py-1.5 max-w-[22rem]">
                        <div class="truncate font-medium" title="{{ $i->nama }}">{{ $sku[$i->campaign_id] ?? ($i->nama ?: 'Iklan ' . $i->campaign_id) }}</div>
                        <div class="text-[10px] text-gray-400">@if ($i->kelompok_id)<span class="text-blue-700">{{ $namaKelompok[$i->kelompok_id] ?? '' }}</span> · @endif#{{ $i->campaign_id }} · {{ $i->bidding_method === 'auto' ? 'otomatis' . ($i->roas_target ? ' (target ROAS ' . (float) $i->roas_target . ')' : '') : 'manual' }}</div>
                    </td>
                    <td class="px-2">
                        <span class="rounded px-1.5 py-0.5 {{ $i->status === 'ongoing' ? 'bg-green-100 text-green-800' : ($i->status === 'paused' ? 'bg-gray-100 text-gray-700' : 'bg-gray-50 text-gray-400') }}">{{ $i->labelStatus() }}</span>
                    </td>
                    <td class="text-right px-2">{{ (float) $i->modal_harian > 0 ? $rp($i->modal_harian) : 'tanpa batas' }}</td>
                    <td class="text-right px-2">{{ $rp($r['biaya'] / $jumlahHari) }}</td>
                    <td class="text-right px-2">{{ $r['hari_ini'] > 0 ? $rp($r['hari_ini']) : '—' }}</td>
                    <td class="text-right px-2">{{ $r['pesanan'] ?: '—' }}</td>
                    <td class="text-right px-2">{{ $r['gmv'] > 0 ? $rp($r['gmv']) : '—' }}</td>
                    <td class="text-right px-2 font-semibold {{ $teksRoas($r['roas']) }}">{{ $roasTeks($r['roas']) }}</td>
                    <td class="px-2 whitespace-nowrap">
                        @if ($r['jam_habis'] !== null)
                            ± jam {{ sprintf('%02d', $r['jam_habis']) }}
                            <span class="text-gray-400">({{ $r['hari_habis'] }}/{{ $r['hari_aktif'] }} hari)</span>
                        @elseif ($r['hari_aktif'])
                            <span class="text-gray-400">tak habis</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="text-right px-3 {{ $stokTotal === 0 ? 'text-red-600 font-semibold' : '' }}">{{ $stokTotal === null ? '—' : number_format($stokTotal, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">Belum ada data iklan.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Harian --}}
<details class="bg-white rounded shadow p-3 text-sm">
    <summary class="cursor-pointer font-semibold">Per hari</summary>
    <div class="overflow-x-auto mt-2">
        <table class="w-full text-xs">
            <thead class="text-gray-500 border-b"><tr>
                <th class="text-left py-1">Tanggal</th><th class="text-right">Tayang</th><th class="text-right">Klik</th><th class="text-right">Belanja</th>
                <th class="text-right">Pesanan</th><th class="text-right">Omzet</th><th class="text-right">ROAS</th>
            </tr></thead>
            <tbody>
            @foreach ($harian as $h)
                <tr class="border-b border-gray-100">
                    <td class="py-1">{{ $h['tanggal']->translatedFormat('D, d M') }}{{ $h['tanggal']->isToday() ? ' (berjalan)' : '' }}</td>
                    <td class="text-right">{{ number_format($h['tayang'], 0, ',', '.') }}</td>
                    <td class="text-right">{{ number_format($h['klik'], 0, ',', '.') }}</td>
                    <td class="text-right">{{ $rp($h['biaya']) }}</td>
                    <td class="text-right">{{ $h['pesanan'] }}</td>
                    <td class="text-right">{{ $rp($h['gmv']) }}</td>
                    <td class="text-right font-semibold {{ $teksRoas($h['roas']) }}">{{ $roasTeks($h['roas']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</details>
@endsection
