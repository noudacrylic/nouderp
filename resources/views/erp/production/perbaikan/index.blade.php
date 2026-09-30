@extends('layouts.erp')

@section('content')
@php
    $rp  = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $statusLabel = [
        'draft' => 'Draf', 'pending' => 'Menunggu', 'confirmed' => 'Dikonfirmasi',
        'in_progress' => 'Dikerjakan', 'partial' => 'Sebagian', 'completed' => 'Selesai dikerjakan',
    ];
    $totalUnit  = $menunggu->sum('qty');
    $totalNilai = $menunggu->sum('nilai');
    $siapOp     = $menunggu->sum('tersedia');
@endphp
<div class="w-full px-6 py-4" x-data="antreanPerbaikan()">

    <div class="flex justify-between items-start mb-5 gap-3 flex-wrap">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Barang Perbaikan</h1>
            <p class="text-xs text-gray-500 mt-0.5 max-w-3xl">
                Barang retur berkondisi <b>Perbaikan</b> masuk Gudang Perbaikan (tidak dijual) dan menunggu di sini sampai
                dibuatkan <b>OP Perbaikan</b>. Setelah OP difinalisasi, barangnya kembali ke stok jual dengan HPP baru.
            </p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari SKU / nama produk…"
                   class="border rounded-lg px-3 py-1.5 text-sm w-64">
            <button class="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm font-semibold">Cari</button>
        </form>
    </div>

    @if(!$gudang)
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
            Gudang Perbaikan belum ada (gudang dengan tanda <i>is_repair</i>).
        </div>
    @else

    {{-- Ringkasan --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Unit di Gudang Perbaikan</div>
            <div class="text-lg font-bold text-gray-800">{{ $qty($totalUnit) }} <span class="text-xs font-normal text-gray-400">· {{ $menunggu->count() }} SKU</span></div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Nilai (HPP)</div>
            <div class="text-lg font-bold text-gray-800">{{ $rp($totalNilai) }}</div>
        </div>
        <div class="bg-white border border-amber-200 rounded-xl px-4 py-3">
            <div class="text-[10px] font-black text-amber-500 uppercase tracking-widest">Belum masuk OP</div>
            <div class="text-lg font-bold text-amber-700">{{ $qty($siapOp) }} unit</div>
        </div>
        <div class="bg-white border border-blue-200 rounded-xl px-4 py-3">
            <div class="text-[10px] font-black text-blue-500 uppercase tracking-widest">OP perbaikan berjalan</div>
            <div class="text-lg font-bold text-blue-700">{{ $diproses->count() }} OP</div>
        </div>
    </div>

    {{-- ═══ Menunggu diperbaiki ═══ --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden mb-6">
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
            <h2 class="font-bold text-gray-800">🔧 Menunggu diperbaiki</h2>
            <button type="button" @click="buatOp()" :disabled="!dipilih().length"
                    class="px-4 py-2 rounded-lg text-sm font-bold transition"
                    :class="dipilih().length ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-100 text-gray-400 cursor-not-allowed'">
                + Buat OP Perbaikan <span x-show="dipilih().length" x-text="'(' + dipilih().length + ' SKU)'"></span>
            </button>
        </div>

        @if($menunggu->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-gray-400">Gudang Perbaikan kosong — tidak ada barang yang menunggu.</div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-[10px] font-black text-gray-400 uppercase tracking-widest">
                <tr>
                    <th class="px-4 py-2 w-10"></th>
                    <th class="px-4 py-2 text-left">Produk</th>
                    <th class="px-4 py-2 text-right w-20">Qty</th>
                    <th class="px-4 py-2 text-right w-28">Belum di OP</th>
                    <th class="px-4 py-2 text-right w-32">Nilai HPP</th>
                    <th class="px-4 py-2 text-left">Asal</th>
                    <th class="px-4 py-2 text-right w-24">Menunggu</th>
                    <th class="px-4 py-2 text-right w-28">Qty ke OP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($menunggu as $m)
                    <tr class="align-top {{ $m['tersedia'] <= 0 ? 'opacity-60' : '' }}">
                        <td class="px-4 py-3">
                            @if($m['tersedia'] > 0)
                                <input type="checkbox" x-model="pilih[{{ $m['product_id'] }}]" class="rounded border-gray-300 text-blue-600">
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-800">{{ $m['nama'] }}</div>
                            <div class="text-[11px] font-mono text-gray-400">{{ $m['sku'] }}</div>
                        </td>
                        <td class="px-4 py-3 text-right font-bold">{{ $qty($m['qty']) }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($m['dipesan'] > 0)
                                <span class="font-bold {{ $m['tersedia'] > 0 ? 'text-amber-700' : 'text-gray-400' }}">{{ $qty($m['tersedia']) }}</span>
                                <div class="text-[10px] text-blue-500">{{ $qty($m['dipesan']) }} sudah di OP</div>
                            @else
                                <span class="font-bold text-amber-700">{{ $qty($m['tersedia']) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($m['nilai'], 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-xs">
                            @foreach($m['asal'] as $a)
                                <div>
                                    @if($a['jenis'] === 'retur' && $a['id'])
                                        <a href="{{ route('sales.returns.show', $a['id']) }}" class="text-blue-600 hover:underline font-semibold">{{ $a['nomor'] }}</a>
                                    @else
                                        <span class="text-gray-600">{{ $a['nomor'] }}</span>
                                    @endif
                                    <span class="text-gray-400">· {{ $qty($a['qty']) }} unit · {{ \Carbon\Carbon::parse($a['tanggal'])->format('d/m/Y') }}</span>
                                    @if($a['pembeli'])<span class="text-gray-400">· {{ $a['pembeli'] }}</span>@endif
                                </div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 text-right text-xs {{ ($m['umur_hari'] ?? 0) > 30 ? 'text-red-600 font-bold' : 'text-gray-500' }}">
                            {{ $m['umur_hari'] !== null ? $m['umur_hari'] . ' hari' : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if($m['tersedia'] > 0)
                                <input type="number" step="any" min="0" max="{{ $m['tersedia'] }}"
                                       x-model="jumlah[{{ $m['product_id'] }}]" x-init="jumlah[{{ $m['product_id'] }}] = {{ $m['tersedia'] }}"
                                       class="w-20 border rounded-lg px-2 py-1 text-sm text-right" :disabled="!pilih[{{ $m['product_id'] }}]">
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    {{-- ═══ Sedang diperbaiki ═══ --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100">
            <h2 class="font-bold text-gray-800">⚙️ Sedang diperbaiki <span class="text-xs font-normal text-gray-400">— OP perbaikan yang belum difinalisasi</span></h2>
        </div>
        @if($diproses->isEmpty())
            <div class="px-5 py-8 text-center text-sm text-gray-400">Tidak ada OP perbaikan yang berjalan.</div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-[10px] font-black text-gray-400 uppercase tracking-widest">
                <tr>
                    <th class="px-4 py-2 text-left w-48">OP</th>
                    <th class="px-4 py-2 text-left w-32">Status</th>
                    <th class="px-4 py-2 text-left">Barang</th>
                    <th class="px-4 py-2 text-right w-28">Dibuat</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($diproses as $op)
                    <tr class="align-top">
                        <td class="px-4 py-3">
                            <a href="{{ route('production.orders.show', $op['id']) }}" class="text-blue-600 hover:underline font-semibold">{{ $op['nomor'] }}</a>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-700">{{ $statusLabel[$op['status']] ?? $op['status'] }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @foreach($op['barang'] as $b)
                                <div>
                                    <span class="font-semibold text-gray-800">{{ $b['nama'] }}</span>
                                    <span class="font-mono text-gray-400">{{ $b['sku'] }}</span>
                                    <span class="text-gray-500">· {{ $qty($b['qty']) }} unit</span>
                                    @if($b['terpakai'] > 0)<span class="text-gray-400">(sudah diambil {{ $qty($b['terpakai']) }})</span>@endif
                                </div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 text-right text-xs text-gray-500">{{ \Carbon\Carbon::parse($op['tanggal'])->format('d/m/Y') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    @endif
</div>

<script>
    function antreanPerbaikan() {
        return {
            pilih: {},
            jumlah: {},
            dipilih() {
                return Object.keys(this.pilih).filter(id => this.pilih[id] && parseFloat(this.jumlah[id]) > 0);
            },
            /** Buka form OP Perbaikan dengan barang terpilih sudah terisi. */
            buatOp() {
                const isi = this.dipilih().map(id => id + ':' + parseFloat(this.jumlah[id])).join(',');
                if (!isi) return;
                window.location = @json(route('production.orders.create')) + '?type=perbaikan&barang=' + encodeURIComponent(isi);
            },
        };
    }
</script>
@endsection
