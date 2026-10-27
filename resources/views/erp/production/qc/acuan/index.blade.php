@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">
    <div class="flex items-start justify-between mb-3">
        <div>
            <h1 class="text-xl font-bold text-gray-800">QC — Sampel Acuan</h1>
            <p class="text-xs text-gray-500">Berat acuan diambil dari penimbangan sampel yang sudah dicek benar, bukan dihitung. Hanya produk ber-profil yang ikut QC timbang.</p>
        </div>
        <a href="{{ route('production.qc-acuan.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-semibold">+ Profil Baru</a>
    </div>
    @include('erp.production.qc._tabs')

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                <tr>
                    <th class="text-left px-4 py-2">Produk</th>
                    <th class="text-left px-4 py-2">Komponen</th>
                    <th class="text-right px-4 py-2">Target</th>
                    <th class="text-right px-4 py-2">Toleransi</th>
                    <th class="text-left px-4 py-2">Sampel</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($profiles as $p)
                    @php $i = $info[$p->id]; @endphp
                    <tr class="border-t border-gray-50 {{ $p->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-2">
                            <div class="font-bold">{{ $p->product?->sku }}</div>
                            <div class="text-xs text-gray-500">{{ $p->product?->name }}</div>
                            @unless($p->is_active)<span class="text-[10px] text-gray-500 font-bold uppercase">nonaktif</span>@endunless
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-600">
                            {{ $p->components->map(fn ($k) => $k->qty . '× ' . $k->name)->implode(', ') ?: '—' }}
                            @if($i['takTerdeteksi'])
                                <div class="text-amber-600">⚠️ terlalu ringan untuk terdeteksi: {{ implode(', ', $i['takTerdeteksi']) }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $i['target'] ? number_format($i['target'], 1, ',', '.') . ' g' : '—' }}</td>
                        <td class="px-4 py-2 text-right">± {{ number_format($i['toleransi'], 1, ',', '.') }} g</td>
                        <td class="px-4 py-2 text-xs text-gray-500">
                            {{ optional($p->sampled_at)->format('d/m/Y') ?? 'belum' }}{{ $p->sampler ? ' · ' . $p->sampler->name : '' }}
                            @if($p->batch_note)<div>{{ $p->batch_note }}</div>@endif
                        </td>
                        <td class="px-4 py-2 text-right"><a href="{{ route('production.qc-acuan.edit', $p->id) }}" class="text-blue-600 text-xs font-semibold hover:underline">Ubah / ambil ulang</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400 text-sm">Belum ada profil QC.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
