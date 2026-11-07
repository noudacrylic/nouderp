@extends('layouts.erp')

@section('content')
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $rp  = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    $sebabLabel = \App\Modules\Production\PrintRusak\PrintRusak::SEBAB;
    $bahanAwal = old('product_id', $bahan->first()?->id);
@endphp
<div class="max-w-6xl mx-auto">
    <a href="{{ route('tasks.meja.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Meja Kerja</a>

    <div class="mb-4">
        <h2 class="text-xl font-bold text-gray-800">Print rusak</h2>
        <p class="text-sm text-gray-500">
            Lembar bahan print yang rusak (paperjam, tinta, salah ukuran, …). Stok bahan langsung berkurang dan nilainya masuk
            <b>HPP</b> lewat akun 5007 Beban Overhead Produksi — tidak dibebankan ke OP tertentu. OP/pesanan hanya keterangan.
        </p>
    </div>

    <div class="grid lg:grid-cols-5 gap-4">
        {{-- Form lapor --}}
        <form method="POST" action="{{ route('tasks.meja.print-rusak.store') }}" class="lg:col-span-2 bg-white shadow rounded-lg border p-5 space-y-4 text-sm" id="form-print-rusak">
            @csrf
            <h3 class="font-semibold text-gray-800">Lapor print rusak</h3>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-xs text-gray-500">Bahan</label>
                    @if ($superAdmin)
                        <button type="button" class="text-xs text-blue-600 hover:underline" onclick="document.getElementById('atur-bahan').classList.toggle('hidden')">+ Tambah bahan</button>
                    @endif
                </div>
                <select name="product_id" class="w-full border rounded px-2 py-1.5" required>
                    @forelse ($bahan as $b)
                        <option value="{{ $b->id }}" data-gudang="{{ $gudangBawaan[$b->id] ?? '' }}" @selected((string) $bahanAwal === (string) $b->id)>{{ $b->name }}</option>
                    @empty
                        <option value="">— belum ada bahan print —</option>
                    @endforelse
                </select>
                @if ($superAdmin)
                    {{-- Pengaturan daftar bahan: form terpisah di luar form lapor (atribut form=). --}}
                    <div id="atur-bahan" class="hidden mt-2 border rounded p-2 bg-gray-50 space-y-2">
                        <div class="flex gap-2">
                            <select name="product_id" form="form-tambah-bahan" class="border rounded px-2 py-1 flex-1 min-w-0 text-xs" required>
                                <option value="">— pilih produk berstok —</option>
                                @foreach ($calonBahan as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach
                            </select>
                            <button form="form-tambah-bahan" class="border border-blue-300 text-blue-700 hover:bg-blue-50 px-2 py-1 rounded text-xs font-semibold">Tambah</button>
                        </div>
                        @if ($bahan->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5 text-xs">
                                @foreach ($bahan as $b)
                                    <span class="flex items-center gap-1 border rounded bg-white px-2 py-0.5">{{ $b->name }}
                                        <button form="form-hapus-bahan" formaction="{{ route('tasks.meja.print-rusak.bahan.hapus', $b->id) }}" class="text-red-500 hover:text-red-700"
                                                title="Hapus dari pilihan" onclick="return confirm('Hapus {{ $b->name }} dari pilihan bahan? Laporan lama tetap ada.')">×</button>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            @if ($bahan->isEmpty())
                <p class="text-amber-700 text-xs">Belum ada bahan print terdaftar{{ $superAdmin ? ' — klik "+ Tambah bahan".' : '. Minta super admin menambahkannya.' }}</p>
            @else
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Jumlah ({{ $bahan->first()->base_unit ?: 'lembar' }})</label>
                        <input type="text" inputmode="decimal" name="qty" value="{{ old('qty') }}" required placeholder="mis. 2" class="w-full border rounded px-2 py-1.5">
                        @error('qty')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Gudang</label>
                        <select name="warehouse_id" class="w-full border rounded px-2 py-1.5">
                            @foreach ($gudang as $g)<option value="{{ $g->id }}" @selected((string) old('warehouse_id', $gudangBawaan[$bahanAwal] ?? '') === (string) $g->id)>{{ $g->name }}</option>@endforeach
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1" id="info-stok"></p>
                    </div>
                </div>

                <div>
                    <div class="text-xs text-gray-500 mb-1">Sebab</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($sebabLabel as $k => $label)
                            <label class="flex items-center gap-1.5 border rounded px-2.5 py-1 cursor-pointer has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                <input type="radio" name="sebab" value="{{ $k }}" @checked(old('sebab', 'paperjam') === $k)> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">OP (opsional)</label>
                        @if ($op)
                            <input type="hidden" name="production_order_id" value="{{ $op->id }}">
                            <div class="border rounded px-2 py-1.5 bg-gray-50">{{ $op->order_number }}</div>
                        @else
                            <div class="border rounded px-2 py-1.5 bg-gray-50 text-gray-400">—</div>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">No. pesanan (opsional)</label>
                        <input type="text" name="so" value="{{ old('so', $soBawaan) }}" placeholder="SO / no. marketplace" class="w-full border rounded px-2 py-1.5">
                        @error('so')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Catatan (opsional)</label>
                    <input type="text" name="catatan" maxlength="500" value="{{ old('catatan') }}" placeholder="mis. printer macet di lembar ke-3" class="w-full border rounded px-2 py-1.5">
                </div>

                <button class="w-full bg-red-600 hover:bg-red-700 text-white py-2 rounded font-semibold">Catat print rusak</button>
            @endif
        </form>

        {{-- Rekap bulanan --}}
        <div class="lg:col-span-3 bg-white shadow rounded-lg border p-5 text-sm">
            <form method="GET" class="flex items-center gap-2 mb-3">
                <h3 class="font-semibold text-gray-800 mr-auto">Rekap bulan</h3>
                @if ($op)<input type="hidden" name="op" value="{{ $op->id }}">@endif
                <input type="month" name="bulan" value="{{ $bulan }}" class="border rounded px-2 py-1 text-sm" onchange="this.form.submit()">
            </form>
            @if ($rekap['bahan']->isEmpty())
                <p class="text-gray-400 text-center py-6">Belum ada print rusak bulan ini.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 border-b">
                        <tr><th class="text-left py-1.5">Bahan</th><th class="text-right">Rusak</th><th class="text-right">Dipakai produksi</th><th class="text-right">% rusak</th><th class="text-right">Nilai</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($rekap['bahan'] as $r)
                            <tr class="border-b last:border-0">
                                <td class="py-1.5">{{ $r->nama }} <span class="text-[11px] text-gray-400">({{ $r->laporan }} laporan)</span></td>
                                <td class="text-right">{{ $fmt($r->rusak) }}</td>
                                <td class="text-right text-gray-500">{{ $fmt($r->produksi) }}</td>
                                <td class="text-right font-semibold {{ ($r->persen ?? 0) >= 10 ? 'text-red-600' : 'text-gray-800' }}">{{ $r->persen === null ? '—' : $fmt($r->persen) . '%' }}</td>
                                <td class="text-right">{{ $rp($r->nilai) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-[11px] text-gray-400 mt-1">% rusak = rusak ÷ (dipakai produksi + rusak) — dasar faktor susut bahan di BOM.</p>

                <div class="mt-4">
                    <div class="text-xs text-gray-500 mb-1">Per sebab</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($rekap['sebab'] as $s)
                            <span class="rounded border px-2 py-1 text-xs">{{ $sebabLabel[$s->sebab] ?? $s->sebab }}: <b>{{ $fmt($s->qty) }}</b> lembar · {{ $rp($s->nilai) }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Riwayat --}}
    <div class="bg-white shadow rounded-lg border mt-4">
        <div class="flex items-center justify-between px-5 py-3 border-b">
            <h3 class="font-semibold text-gray-800">Riwayat</h3>
            <form method="GET">@if ($op)<input type="hidden" name="op" value="{{ $op->id }}">@endif<input type="hidden" name="bulan" value="{{ $bulan }}">@include('erp._partials.per-page-select')</form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 bg-gray-50">
                    <tr>
                        <th class="text-left px-4 py-2">Nomor</th><th class="text-left">Tanggal</th><th class="text-left">Bahan</th><th class="text-right">Jumlah</th>
                        <th class="text-left pl-4">Sebab</th><th class="text-left">OP / Pesanan</th><th class="text-left">Pelapor</th><th class="text-right">Nilai</th><th class="text-right px-4"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($riwayat as $r)
                        <tr class="border-t {{ $r->isVoided() ? 'text-gray-400 line-through' : '' }}">
                            <td class="px-4 py-2 font-mono text-xs">{{ $r->nomor }}</td>
                            <td>{{ $r->tanggal?->format('d/m/Y') }}</td>
                            <td>{{ $r->product?->name }}<div class="text-[11px] text-gray-400 no-underline">{{ $r->warehouse?->name }}</div></td>
                            <td class="text-right">{{ $fmt($r->qty) }}</td>
                            <td class="pl-4">{{ $r->labelSebab() }}@if ($r->catatan)<div class="text-[11px] text-gray-400">{{ $r->catatan }}</div>@endif</td>
                            <td class="text-xs">
                                @if ($r->productionOrder)<a href="{{ route('production.orders.show', $r->production_order_id) }}" class="text-blue-600 hover:underline">{{ $r->productionOrder->order_number }}</a>@endif
                                @if ($r->salesOrder)<div>{{ $r->salesOrder->order_number }}</div>@endif
                            </td>
                            <td class="text-xs">{{ $r->user?->name }}</td>
                            <td class="text-right">{{ $rp($r->nilai) }}</td>
                            <td class="text-right px-4">
                                @if ($r->isVoided())
                                    <span class="text-[11px] no-underline">Dibatalkan</span>
                                @elseif ($r->canBeVoided(auth()->user()))
                                    <form method="POST" action="{{ route('tasks.meja.print-rusak.void', $r->id) }}" onsubmit="return confirm('Batalkan {{ $r->nomor }}? Stok dikembalikan dan jurnal dibalik.')">@csrf
                                        <button class="text-xs text-red-600 hover:underline">Batalkan</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-gray-400 py-6">Belum ada laporan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3">{{ $riwayat->links() }}</div>
    </div>

    @if ($superAdmin)
        <form id="form-tambah-bahan" method="POST" action="{{ route('tasks.meja.print-rusak.bahan.tambah') }}" class="hidden">@csrf</form>
        <form id="form-hapus-bahan" method="POST" class="hidden">@csrf @method('DELETE')</form>
    @endif
</div>

<script>
(function () {
    const form = document.getElementById('form-print-rusak');
    if (!form) return;
    const stok = @json($stok);
    const gudang = form.querySelector('select[name=warehouse_id]');
    const info = document.getElementById('info-stok');
    const bahan = form.querySelector('select[name=product_id]:not([form])');
    const tampil = () => {
        if (!bahan || !bahan.value || !gudang) return;
        const q = (stok[bahan.value] || {})[gudang.value];
        info.textContent = 'Stok: ' + (q === undefined ? 0 : Number(q).toLocaleString('id-ID'));
    };
    bahan && bahan.addEventListener('change', () => {
        const g = bahan.selectedOptions[0]?.dataset.gudang;
        if (g) gudang.value = g;
        tampil();
    });
    gudang && gudang.addEventListener('change', tampil);
    tampil();
})();
</script>
@endsection
