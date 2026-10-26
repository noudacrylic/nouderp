@extends('layouts.erp')

@section('content')
@php
    use App\Modules\Marketplace\Shopee\Models\ShopeeWalletTransaction as Mutasi;
    $rp = fn ($v) => ($v < 0 ? '−' : '') . 'Rp' . number_format(abs((float) $v), 0, ',', '.');
    $warnaCocok = [Mutasi::COCOK => 'bg-green-100 text-green-800', Mutasi::SELISIH => 'bg-amber-100 text-amber-800', Mutasi::BELUM => 'bg-red-100 text-red-700'];
    $t = $ringkas['terakhir'];
    $selisihAkhir = $t && $ringkas['erp'] !== null ? round($ringkas['erp'] - (float) $t->saldo_akhir, 2) : null;
@endphp

<div class="flex items-start justify-between mb-4 flex-wrap gap-3">
    <div>
        <a href="{{ route('finance.cash-bank.settlements.index') }}" class="text-xs text-blue-600 hover:underline">← Rekonsiliasi Marketplace</a>
        <h1 class="text-lg font-semibold">Mutasi Saldo Shopee</h1>
        <p class="text-xs text-gray-500 max-w-3xl">
            Setiap baris mutasi Saldo Penjual dicocokkan dengan jurnal akun <b>1104 Saldo Penjualan Shopee</b>:
            pesanan per nomor pesanan (tanggal boleh beda), penarikan/iklan/penyesuaian/SPinjam per nominal dalam ±3 hari.
            Halaman ini hanya membaca — belum memposting jurnal.
        </p>
    </div>
    <div class="flex flex-wrap items-end gap-2">
        <form method="POST" action="{{ route('finance.cash-bank.settlements.mutasi-shopee.impor') }}" enctype="multipart/form-data" class="flex items-end gap-2">
            @csrf
            <div>
                <label class="block text-xs text-gray-500 mb-1">File mutasi Seller Centre (.xlsx, boleh banyak)</label>
                <input type="file" name="files[]" accept=".xlsx" multiple required class="text-xs border rounded px-2 py-1 bg-white">
            </div>
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm font-semibold">Unggah &amp; cocokkan</button>
        </form>
        @if ($ringkas['n'])
            <form method="POST" action="{{ route('finance.cash-bank.settlements.mutasi-shopee.cocokkan') }}">@csrf
                <button class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-2 rounded text-sm">Cocokkan ulang</button>
            </form>
        @endif
    </div>
</div>

@if (!$ringkas['n'])
    <div class="bg-white rounded shadow p-6 text-sm text-gray-600">
        Belum ada data. Unduh dari Seller Centre › <b>Keuangan › Saldo Penjual › Riwayat Transaksi › Unduh</b>
        (file <span class="font-mono">my_balance_transaction_report…xlsx</span>), lalu unggah di atas.
        Rentang yang bertumpuk aman — baris yang sudah ada dilewati.
    </div>
@else
    {{-- Ringkasan --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="bg-white rounded shadow px-4 py-3">
            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Saldo Shopee terakhir</div>
            <div class="text-lg font-bold">{{ $rp($t->saldo_akhir) }}</div>
            <div class="text-[11px] text-gray-400">{{ $t->waktu->format('d/m/Y H:i') }} · data {{ \Carbon\Carbon::parse($ringkas['dari'])->format('d/m/Y') }}–{{ \Carbon\Carbon::parse($ringkas['sampai'])->format('d/m/Y') }}</div>
        </div>
        <div class="bg-white rounded shadow px-4 py-3">
            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Saldo 1104 ERP (tgl sama)</div>
            <div class="text-lg font-bold">{{ $ringkas['erp'] === null ? '—' : $rp($ringkas['erp']) }}</div>
            <div class="text-[11px] {{ $selisihAkhir && abs($selisihAkhir) >= 1 ? 'text-red-600 font-semibold' : 'text-green-700' }}">
                {{ $selisihAkhir === null ? '' : (abs($selisihAkhir) < 1 ? 'Sama dengan Shopee' : 'Selisih ' . $rp($selisihAkhir) . ($selisihAkhir > 0 ? ' (ERP lebih besar)' : ' (ERP lebih kecil)')) }}
            </div>
        </div>
        @foreach ([Mutasi::SELISIH, Mutasi::BELUM] as $k)
            @php $c = $ringkas['cocok'][$k] ?? null; @endphp
            <a href="{{ route('finance.cash-bank.settlements.mutasi-shopee.index', ['tab' => 'transaksi', 'cocok' => $k]) }}" class="bg-white rounded shadow px-4 py-3 hover:ring-2 hover:ring-blue-200">
                <div class="text-[10px] font-black uppercase tracking-widest {{ $k === Mutasi::BELUM ? 'text-red-500' : 'text-amber-500' }}">{{ Mutasi::LABEL_COCOK[$k] }}</div>
                <div class="text-lg font-bold">{{ $c?->n ?? 0 }} baris</div>
                <div class="text-[11px] text-gray-400">nilai Shopee {{ $rp($c?->total ?? 0) }} · {{ ($ringkas['cocok'][Mutasi::COCOK]->n ?? 0) }} baris cocok</div>
            </a>
        @endforeach
    </div>

    @if ($ringkas['kategori']->isNotEmpty())
        <div class="bg-white rounded shadow p-3 mb-4 text-xs flex flex-wrap gap-2 items-center">
            <span class="text-gray-500 font-semibold">Belum cocok per jenis:</span>
            @foreach ($ringkas['kategori'] as $k)
                <a href="{{ route('finance.cash-bank.settlements.mutasi-shopee.index', ['tab' => 'transaksi', 'kategori' => $k->kategori]) }}"
                   class="border rounded px-2 py-1 hover:bg-gray-50">{{ Mutasi::LABEL_KATEGORI[$k->kategori] ?? $k->kategori }} · {{ $k->n }} · {{ $rp($k->total) }}</a>
            @endforeach
        </div>
    @endif

    {{-- Tab --}}
    <div class="flex gap-1 border-b border-gray-200 mb-3">
        @foreach (['harian' => 'Saldo harian', 'transaksi' => 'Transaksi'] as $k => $label)
            <a href="{{ route('finance.cash-bank.settlements.mutasi-shopee.index', ['tab' => $k]) }}"
               class="px-4 py-2 text-sm font-bold border-b-2 -mb-px {{ $tab === $k ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if ($tab === 'harian')
        <form method="GET" class="flex items-end gap-2 mb-3 text-sm">
            <input type="hidden" name="tab" value="harian">
            <div><label class="block text-xs text-gray-500 mb-1">Dari</label><input type="date" name="dari" value="{{ request('dari') }}" class="border rounded px-2 py-1"></div>
            <div><label class="block text-xs text-gray-500 mb-1">Sampai</label><input type="date" name="sampai" value="{{ request('sampai') }}" class="border rounded px-2 py-1"></div>
            <button class="bg-gray-600 text-white px-3 py-1.5 rounded text-xs">Tampilkan</button>
        </form>
        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-[10px] font-black text-gray-400 uppercase tracking-widest">
                    <tr>
                        <th class="text-left px-3 py-2">Tanggal</th>
                        <th class="text-right px-3 py-2">Masuk</th>
                        <th class="text-right px-3 py-2">Keluar</th>
                        <th class="text-right px-3 py-2">Saldo Shopee</th>
                        <th class="text-right px-3 py-2">Saldo 1104 ERP</th>
                        <th class="text-right px-3 py-2">Selisih (ERP − Shopee)</th>
                        <th class="text-right px-3 py-2" title="Selisih hari ini dikurangi selisih hari sebelumnya — bukan nol berarti ada yang tak terjurnal/berbeda di hari ini">Berubah hari ini</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_reverse($harian) as $h)
                        <tr class="border-t {{ abs($h['berubah']) >= 1 ? 'bg-amber-50' : '' }}">
                            <td class="px-3 py-1.5 whitespace-nowrap">
                                <a href="{{ route('finance.cash-bank.settlements.mutasi-shopee.index', ['tab' => 'transaksi', 'dari' => $h['tanggal'], 'sampai' => $h['tanggal']]) }}" class="text-blue-600 hover:underline">{{ \Carbon\Carbon::parse($h['tanggal'])->format('d/m/Y') }}</a>
                                <span class="text-[11px] text-gray-400">· {{ $h['n'] }} baris</span>
                            </td>
                            <td class="px-3 py-1.5 text-right font-mono text-green-700">{{ $rp($h['masuk']) }}</td>
                            <td class="px-3 py-1.5 text-right font-mono text-red-600">{{ $rp($h['keluar']) }}</td>
                            <td class="px-3 py-1.5 text-right font-mono">{{ $rp($h['shopee']) }}</td>
                            <td class="px-3 py-1.5 text-right font-mono">{{ $rp($h['erp']) }}</td>
                            <td class="px-3 py-1.5 text-right font-mono {{ abs($h['selisih']) >= 1 ? 'text-red-600' : 'text-green-700' }}">{{ $rp($h['selisih']) }}</td>
                            <td class="px-3 py-1.5 text-right font-mono {{ abs($h['berubah']) >= 1 ? 'font-bold text-amber-700' : 'text-gray-300' }}">{{ abs($h['berubah']) >= 1 ? $rp($h['berubah']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-gray-400 mt-2">
            Pelepasan dana pesanan sering dijurnal di tanggal berbeda dari tanggal Shopee, jadi selisih harian bisa naik-turun lalu kembali.
            Yang perlu dikejar: selisih yang <b>menetap</b> sejak hari tertentu.
        </p>
    @else
        <form method="GET" class="flex flex-wrap items-end gap-2 mb-3 text-sm">
            <input type="hidden" name="tab" value="transaksi">
            <div><label class="block text-xs text-gray-500 mb-1">Cari</label><input type="text" name="q" value="{{ request('q') }}" placeholder="No. pesanan / deskripsi" class="border rounded px-2 py-1 w-52"></div>
            <div><label class="block text-xs text-gray-500 mb-1">Cocok</label>
                <select name="cocok" class="border rounded px-2 py-1">
                    <option value="">Semua</option>
                    @foreach (Mutasi::LABEL_COCOK as $k => $l)<option value="{{ $k }}" @selected(request('cocok') === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div><label class="block text-xs text-gray-500 mb-1">Jenis</label>
                <select name="kategori" class="border rounded px-2 py-1">
                    <option value="">Semua</option>
                    @foreach (Mutasi::LABEL_KATEGORI as $k => $l)<option value="{{ $k }}" @selected(request('kategori') === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div><label class="block text-xs text-gray-500 mb-1">Dari</label><input type="date" name="dari" value="{{ request('dari') }}" class="border rounded px-2 py-1"></div>
            <div><label class="block text-xs text-gray-500 mb-1">Sampai</label><input type="date" name="sampai" value="{{ request('sampai') }}" class="border rounded px-2 py-1"></div>
            <button class="bg-gray-600 text-white px-3 py-1.5 rounded text-xs">Saring</button>
            <div class="ml-auto">@include('erp._partials.per-page-select')</div>
        </form>
        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-[10px] font-black text-gray-400 uppercase tracking-widest">
                    <tr>
                        <th class="text-left px-3 py-2">Waktu</th>
                        <th class="text-left px-3 py-2">Transaksi</th>
                        <th class="text-right px-3 py-2">Jumlah</th>
                        <th class="text-right px-3 py-2">Saldo akhir</th>
                        <th class="text-left px-3 py-2">Jurnal ERP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transaksi as $m)
                        <tr class="border-t align-top">
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">{{ $m->waktu->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2">
                                <div class="font-semibold text-gray-800">{{ $m->tipe }}</div>
                                <div class="text-xs text-gray-500">{{ $m->deskripsi }}</div>
                                <div class="text-[11px] text-gray-400">{{ Mutasi::LABEL_KATEGORI[$m->kategori] ?? $m->kategori }}@if ($m->order_sn) · <span class="font-mono">{{ $m->order_sn }}</span>@endif @if ($m->status) · {{ $m->status }}@endif</div>
                            </td>
                            <td class="px-3 py-2 text-right font-mono {{ $m->jumlah < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $rp($m->jumlah) }}</td>
                            <td class="px-3 py-2 text-right font-mono text-gray-500">{{ $m->saldo_akhir === null ? '—' : $rp($m->saldo_akhir) }}</td>
                            <td class="px-3 py-2 text-xs">
                                @if ($m->cocok)
                                    <span class="rounded px-1.5 py-0.5 font-semibold {{ $warnaCocok[$m->cocok] ?? '' }}">{{ Mutasi::LABEL_COCOK[$m->cocok] ?? $m->cocok }}</span>
                                @endif
                                @foreach ($m->journal_ids ?? [] as $jid)
                                    <span class="font-mono text-gray-500">{{ $nomorJurnal[$jid] ?? '#' . $jid }}</span>
                                @endforeach
                                @if ($m->cocok_catatan)<div class="text-gray-500 mt-0.5">{{ $m->cocok_catatan }}</div>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Tidak ada baris yang cocok dengan saringan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $transaksi->links() }}</div>
    @endif
@endif
@endsection
