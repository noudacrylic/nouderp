@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">
    <div class="mb-3">
        <h1 class="text-xl font-bold text-gray-800">QC — Setting Toleransi</h1>
        <p class="text-xs text-gray-500">Toleransi bawaan dipakai profil yang tidak mengatur toleransinya sendiri.</p>
    </div>
    @include('erp.production.qc._tabs')

    <div class="grid grid-cols-12 gap-5">
        <form method="POST" action="{{ route('production.qc-acuan.toleransi.save') }}"
              class="col-span-4 bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-3 h-fit">
            @csrf
            <div class="font-bold text-gray-700 text-sm">Toleransi bawaan</div>
            <div class="flex items-center gap-2">
                <span class="text-sm">±</span>
                <input type="number" step="0.1" min="0.1" name="value" value="{{ $bawaan['value'] }}" required
                       class="w-28 border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-right">
                <select name="mode" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm">
                    <option value="persen" @selected($bawaan['mode'] === 'persen')>% dari target</option>
                    <option value="gram" @selected($bawaan['mode'] === 'gram')>gram</option>
                </select>
            </div>
            <p class="text-[11px] text-gray-500">Panel akrilik bervariasi beberapa persen karena tebal lembaran — persen cocok untuk paket panel.
                Kantong komponen kecil sebaiknya diberi toleransi gram yang ketat di profilnya sendiri.</p>

            <div class="pt-3 border-t border-gray-100 font-bold text-gray-700 text-sm">Divisi pelaksana QC</div>
            <select name="qc_department_id" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm">
                <option value="">Ikut divisi langkah terakhir (mis. Assembling)</option>
                @foreach($divisi as $d)
                    <option value="{{ $d->id }}" @selected((int) $divisiQc === $d->id)>{{ $d->name }}</option>
                @endforeach
            </select>
            <p class="text-[11px] text-gray-500">Langkah "QC Timbang" ditambahkan otomatis di ujung OP yang hasilnya ber-profil QC, masuk antrean divisi ini.
                Penimbang dipilih dari eksekutor divisi ini. Berlaku untuk langkah QC yang dibuat setelah disimpan.</p>
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-semibold">Simpan</button>
        </form>

        <div class="col-span-8 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-4 py-3 font-bold text-gray-700 text-sm border-b border-gray-100">Daya deteksi per produk</div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th class="text-left px-4 py-2">Produk</th><th class="text-right px-4 py-2">Target</th><th class="text-right px-4 py-2">Toleransi</th><th class="text-left px-4 py-2">Komponen tak terdeteksi</th></tr>
                </thead>
                <tbody>
                    @forelse($profiles as $p)
                        @php $i = $info[$p->id]; @endphp
                        <tr class="border-t border-gray-50">
                            <td class="px-4 py-2"><a href="{{ route('production.qc-acuan.edit', $p->id) }}" class="font-bold text-blue-600 hover:underline">{{ $p->product?->sku }}</a>
                                <span class="text-xs text-gray-400">{{ $p->tolerance_mode ? 'toleransi sendiri' : 'ikut bawaan' }}</span></td>
                            <td class="px-4 py-2 text-right">{{ $i['target'] ? number_format($i['target'], 1, ',', '.') . ' g' : '—' }}</td>
                            <td class="px-4 py-2 text-right">± {{ number_format($i['toleransi'], 1, ',', '.') }} g</td>
                            <td class="px-4 py-2 text-xs">
                                @forelse($i['takTerdeteksi'] as $k)
                                    <span class="text-amber-700">⚠️ {{ $k->name }} ({{ number_format($k->unit_gram, 1, ',', '.') }} g)</span>@if(!$loop->last), @endif
                                @empty
                                    <span class="text-emerald-600">✓ semua terdeteksi</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">Belum ada profil aktif.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
