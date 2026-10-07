@extends('layouts.erp')

@section('content')
@php
    $rp  = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $statusLabel = [
        'draft' => 'Draf', 'pending' => 'Menunggu', 'confirmed' => 'Dikonfirmasi',
        'in_progress' => 'Dikerjakan', 'partial' => 'Sebagian', 'completed' => 'Selesai dikerjakan',
        'finalized' => 'Difinalisasi', 'cancelled' => 'Dibatalkan',
    ];
    $totalUnit  = $menunggu->sum('qty');
    $totalNilai = $menunggu->sum('nilai');
    $belumKel   = $menunggu->sum('tersedia');
    $hppUnit    = $menunggu->pluck('hpp_unit', 'product_id');
@endphp
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-start mb-4 gap-3 flex-wrap">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Barang Perbaikan</h1>
            <p class="text-xs text-gray-500 mt-0.5 max-w-3xl">
                Barang retur berkondisi <b>Perbaikan</b> dan barang rusak temuan <b>opname</b> masuk Gudang Perbaikan (tidak dijual).
                Kumpulkan dulu dalam <b>Kelompok</b> — boleh beda produk — lalu satu kelompok dikerjakan dalam satu <b>OP Perbaikan</b>.
                Setelah OP difinalisasi, barangnya kembali ke stok jual dengan HPP baru.
            </p>
        </div>
        @if($tab === 'antrean')
            <form method="GET" class="flex items-center gap-2">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari SKU / nama produk…"
                       class="border rounded-lg px-3 py-1.5 text-sm w-64">
                <button class="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm font-semibold">Cari</button>
            </form>
        @endif
    </div>

    {{-- Tab --}}
    <div class="flex gap-1 border-b border-gray-200 mb-5">
        <a href="{{ route('production.perbaikan.index') }}"
           class="px-4 py-2 text-sm font-bold border-b-2 -mb-px {{ $tab === 'antrean' ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            Antrean
        </a>
        <a href="{{ route('production.perbaikan.index', ['tab' => 'kelompok']) }}"
           class="px-4 py-2 text-sm font-bold border-b-2 -mb-px {{ $tab === 'kelompok' ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            Kelompok
            @if($jmlTerbuka)<span class="ml-1 text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full">{{ $jmlTerbuka }} terbuka</span>@endif
        </a>
    </div>

    @if(!$gudang)
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
            Gudang Perbaikan belum ada (gudang dengan tanda <i>is_repair</i>).
        </div>
    @elseif($tab === 'antrean')

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
            <div class="text-[10px] font-black text-amber-500 uppercase tracking-widest">Belum dikelompokkan</div>
            <div class="text-lg font-bold text-amber-700">{{ $qty($belumKel) }} unit</div>
        </div>
        <div class="bg-white border border-blue-200 rounded-xl px-4 py-3">
            <div class="text-[10px] font-black text-blue-500 uppercase tracking-widest">OP perbaikan berjalan</div>
            <div class="text-lg font-bold text-blue-700">{{ $diproses->count() }} OP</div>
        </div>
    </div>

    {{-- ═══ Menunggu diperbaiki ═══ --}}
    <form method="POST" action="{{ route('production.perbaikan.kelompok.store') }}" x-data="antreanPerbaikan()"
          @submit="if (!dipilih().length) { $event.preventDefault(); }"
          class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden mb-6">
        @csrf
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 gap-3 flex-wrap">
            <h2 class="font-bold text-gray-800">🔧 Menunggu diperbaiki</h2>
            <div class="flex items-center gap-2" x-show="dipilih().length" x-cloak>
                <input type="text" name="name" x-model="nama" required maxlength="255"
                       placeholder="Nama kelompok, mis. Brosur KD – Okt"
                       class="border rounded-lg px-3 py-2 text-sm w-72">
                <button type="submit"
                        class="px-4 py-2 rounded-lg text-sm font-bold bg-blue-600 text-white hover:bg-blue-700 transition">
                    + Buat Kelompok <span x-text="'(' + dipilih().length + ' SKU · HPP ' + rupiah(totalHpp()) + ')'"></span>
                </button>
            </div>
            <span class="text-xs text-gray-400" x-show="!dipilih().length">Centang barang yang HPP-nya sebanding untuk dijadikan satu kelompok.</span>
        </div>

        @if($menunggu->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-gray-400">Gudang Perbaikan kosong — tidak ada barang yang menunggu.</div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-[10px] font-black text-gray-400 uppercase tracking-widest">
                <tr>
                    <th class="px-4 py-2 w-10"></th>
                    <th class="px-4 py-2 text-left">Produk</th>
                    <th class="px-4 py-2 text-right w-16">Qty</th>
                    <th class="px-4 py-2 text-right w-32">Belum dikelompokkan</th>
                    <th class="px-4 py-2 text-right w-24">HPP / unit</th>
                    <th class="px-4 py-2 text-right w-28">Nilai HPP</th>
                    <th class="px-4 py-2 text-left">Asal</th>
                    <th class="px-4 py-2 text-right w-24">Menunggu</th>
                    <th class="px-4 py-2 text-right w-28">Qty ke kelompok</th>
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
                            <span class="font-bold {{ $m['tersedia'] > 0 ? 'text-amber-700' : 'text-gray-400' }}">{{ $qty($m['tersedia']) }}</span>
                            @if($m['di_kelompok'] > 0)<div class="text-[10px] text-amber-600">{{ $qty($m['di_kelompok']) }} di kelompok</div>@endif
                            @if($m['di_op'] > 0)<div class="text-[10px] text-blue-500">{{ $qty($m['di_op']) }} di OP</div>@endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($m['hpp_unit'], 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($m['nilai'], 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-xs">
                            @foreach($m['asal'] as $a)
                                <div>
                                    @if($a['jenis'] === 'retur' && $a['id'])
                                        <a href="{{ route('sales.returns.show', $a['id']) }}" class="text-blue-600 hover:underline font-semibold">{{ $a['nomor'] }}</a>
                                    @elseif($a['jenis'] === 'opname' && $a['id'])
                                        <span class="text-[9px] font-black bg-purple-100 text-purple-700 px-1 py-0.5 rounded">OPNAME</span>
                                        <a href="{{ route('inventory.adjustments.edit', $a['id']) }}" class="text-blue-600 hover:underline font-semibold">{{ $a['nomor'] }}</a>
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
                                       name="items[{{ $m['product_id'] }}]"
                                       x-model="jumlah[{{ $m['product_id'] }}]" x-init="jumlah[{{ $m['product_id'] }}] = {{ $m['tersedia'] }}; hpp[{{ $m['product_id'] }}] = {{ $m['hpp_unit'] }}"
                                       class="w-20 border rounded-lg px-2 py-1 text-sm text-right" :disabled="!pilih[{{ $m['product_id'] }}]">
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </form>

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
                    <th class="px-4 py-2 text-left w-48">Kelompok</th>
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
                        <td class="px-4 py-3 text-xs">
                            @if($op['kelompok'])
                                <div class="font-semibold text-gray-700">{{ $op['kelompok']['nomor'] }}</div>
                                <div class="text-gray-400">{{ $op['kelompok']['nama'] }}</div>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
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

    @else
    {{-- ═══ Tab Kelompok ═══ --}}
    @php
        $badge = [
            'terbuka' => 'bg-amber-100 text-amber-700', 'di_op' => 'bg-blue-100 text-blue-700',
            'selesai' => 'bg-green-100 text-green-700', 'batal' => 'bg-gray-100 text-gray-500',
        ];
    @endphp
    <form method="GET" class="flex items-center gap-2 mb-3">
        <input type="hidden" name="tab" value="kelompok">
        <select name="status" onchange="this.form.submit()" class="border rounded-lg px-3 py-1.5 text-sm">
            <option value="">Semua status</option>
            @foreach(\App\Modules\Production\Models\RepairGroup::STATUS_LABEL as $k => $v)
                <option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>
            @endforeach
        </select>
        <div class="ml-auto">@include('erp._partials.per-page-select')</div>
    </form>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        @if($kelompok->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-gray-400">
                Belum ada kelompok. Buat dari tab <a href="{{ route('production.perbaikan.index') }}" class="text-blue-600 hover:underline">Antrean</a> — centang barang lalu klik <b>+ Buat Kelompok</b>.
            </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-[10px] font-black text-gray-400 uppercase tracking-widest">
                <tr>
                    <th class="px-4 py-2 text-left w-44">Kelompok</th>
                    <th class="px-4 py-2 text-left">Barang</th>
                    <th class="px-4 py-2 text-right w-20">Unit</th>
                    <th class="px-4 py-2 text-left w-28">Status</th>
                    <th class="px-4 py-2 text-left w-40">OP</th>
                    <th class="px-4 py-2 text-right w-56"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($kelompok as $g)
                    <tr class="align-top">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-800">{{ $g->number }}</div>
                            <div class="text-xs text-gray-500">{{ $g->name }}</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">{{ $g->created_at->format('d/m/Y') }}{{ $g->creator ? ' · ' . $g->creator->name : '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @foreach($g->items as $it)
                                <div>
                                    <span class="font-semibold text-gray-800">{{ $it->product?->name ?? ('Produk #' . $it->product_id) }}</span>
                                    <span class="font-mono text-gray-400">{{ $it->product?->sku }}</span>
                                    <span class="text-gray-500">· {{ $qty($it->qty) }} unit</span>
                                    @if($g->isTerbuka() && isset($hppUnit[$it->product_id]))
                                        <span class="text-gray-400">· HPP {{ number_format($hppUnit[$it->product_id], 0, ',', '.') }}</span>
                                    @endif
                                </div>
                            @endforeach
                            @if($g->notes)<div class="text-gray-400 italic mt-1">{{ $g->notes }}</div>@endif
                        </td>
                        <td class="px-4 py-3 text-right font-bold">{{ $qty($g->items->sum('qty')) }}</td>
                        <td class="px-4 py-3">
                            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full {{ $badge[$g->status] ?? 'bg-gray-100' }}">{{ $g->statusLabel() }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if($g->productionOrder)
                                <a href="{{ route('production.orders.show', $g->productionOrder->id) }}" class="text-blue-600 hover:underline font-semibold">{{ $g->productionOrder->order_number }}</a>
                                <div class="text-gray-400">{{ $statusLabel[$g->productionOrder->status] ?? $g->productionOrder->status }}</div>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($g->isTerbuka())
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('production.orders.create', ['type' => 'perbaikan', 'kelompok' => $g->id]) }}"
                                       class="px-3 py-1.5 rounded-lg text-xs font-bold bg-blue-600 text-white hover:bg-blue-700">Buat OP</a>
                                    <a href="{{ route('production.perbaikan.kelompok.edit', $g->id) }}"
                                       class="px-3 py-1.5 rounded-lg text-xs font-bold border border-gray-300 text-gray-600 hover:bg-gray-50">Ubah</a>
                                    <form method="POST" action="{{ route('production.perbaikan.kelompok.cancel', $g->id) }}"
                                          onsubmit="return confirm('Batalkan kelompok {{ $g->number }}? Barangnya kembali ke antrean.')">
                                        @csrf
                                        <button class="px-3 py-1.5 rounded-lg text-xs font-bold border border-red-200 text-red-600 hover:bg-red-50">Batal</button>
                                    </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-4 py-3 border-t border-gray-100">{{ $kelompok->links() }}</div>
        @endif
    </div>
    @endif
</div>

<script>
    function antreanPerbaikan() {
        return {
            pilih: {},
            jumlah: {},
            hpp: {},
            nama: '',
            dipilih() {
                return Object.keys(this.pilih).filter(id => this.pilih[id] && parseFloat(this.jumlah[id]) > 0);
            },
            /** Total HPP awal barang terpilih — bantu operator menilai kelompoknya. */
            totalHpp() {
                return this.dipilih().reduce((s, id) => s + (parseFloat(this.jumlah[id]) || 0) * (this.hpp[id] || 0), 0);
            },
            rupiah(v) {
                return 'Rp ' + Math.round(v).toLocaleString('id-ID');
            },
        };
    }
</script>
@endsection
