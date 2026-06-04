@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')
@include('erp.sdm.kebijakan._subtabs')

@php
    use App\Modules\SDM\Models\KebijakanRule;
    $targetLabel = collect($targets)->keyBy('key');
@endphp

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Rule Kebijakan</h1>
    <a href="{{ route('sdm.kebijakan.rule.create') }}" class="bg-emerald-600 text-white px-4 py-2 rounded text-sm font-semibold">+ Rule Baru</a>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif

<div class="bg-white rounded shadow mb-4 p-4">
    <p class="text-sm text-gray-600">
        Setiap rule berisi <strong>kondisi</strong> (digabung dengan AND) dan <strong>akibat</strong> (set nilai kolom).
        Saat ada beberapa rule yang akibatnya mengisi kolom yang sama, <strong>rule dengan prioritas lebih kecil menang</strong>. Rule dengan kolom berbeda saling melengkapi.
    </p>
</div>

<div class="bg-white rounded shadow overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-xs text-gray-600 uppercase">
            <tr>
                <th class="px-3 py-2 text-left w-16">Prio</th>
                <th class="px-3 py-2 text-left">Nama Rule</th>
                <th class="px-3 py-2 text-left">Kondisi (AND)</th>
                <th class="px-3 py-2 text-left">Akibat</th>
                <th class="px-3 py-2 text-center w-16">Aktif</th>
                <th class="px-3 py-2 text-right w-32">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rules as $r)
                <tr class="border-b align-top hover:bg-gray-50">
                    <td class="px-3 py-3">{{ $r->priority }}</td>
                    <td class="px-3 py-3">
                        <div class="font-semibold">{{ $r->nama }}</div>
                        @if($r->deskripsi)
                            <div class="text-xs text-gray-500 mt-0.5">{{ $r->deskripsi }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-3">
                        <div class="flex flex-wrap gap-1">
                            @foreach($r->conditions ?? [] as $c)
                                @php
                                    $field = KebijakanRule::FIELDS[$c['field']]['label'] ?? $c['field'];
                                    $op    = KebijakanRule::OPERATORS[$c['op']] ?? $c['op'];
                                    $val   = $c['value'] ?? '';
                                    $valStr = is_array($val) ? implode(', ', $val) : $val;
                                @endphp
                                <span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">{{ $field }} {{ $op }} <span class="font-mono">{{ $valStr }}</span></span>
                            @endforeach
                            @if(empty($r->conditions))
                                <span class="text-xs text-gray-400 italic">(tanpa kondisi → selalu match)</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-3 py-3">
                        <div class="space-y-1">
                            @foreach($r->effects ?? [] as $e)
                                @php
                                    $tgt = $targetLabel[$e['kolom']] ?? null;
                                    $kolLabel = $tgt['label'] ?? $e['kolom'];
                                    $grp      = $tgt['group']  ?? null;
                                    $kind = KebijakanRule::EFFECT_KINDS[$e['kind']] ?? $e['kind'];
                                    $val  = $e['value'] ?? '';
                                @endphp
                                <div class="text-xs">
                                    → <span class="font-semibold text-emerald-700">{{ $kolLabel }}</span>
                                    @if($grp) <span class="text-[10px] text-gray-400">[{{ $grp }}]</span> @endif
                                    <span class="text-gray-500">= {{ $kind }}</span>
                                    @if($val !== '' && $val !== null) <span class="font-mono">({{ $val }})</span> @endif
                                </div>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-3 py-3 text-center">
                        @if($r->is_active)
                            <span class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">ON</span>
                        @else
                            <span class="text-[10px] bg-gray-200 text-gray-500 px-2 py-0.5 rounded">OFF</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 text-right">
                        <a href="{{ route('sdm.kebijakan.rule.edit', $r->id) }}" class="text-xs bg-blue-600 text-white px-3 py-1.5 rounded">Edit</a>
                        <button type="button" onclick="document.getElementById('del-rule-{{ $r->id }}').submit()" class="text-xs bg-red-600 text-white px-3 py-1.5 rounded">Hapus</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400 text-sm">Belum ada rule. Klik "+ Rule Baru" untuk menambah.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@foreach($rules as $r)
    <form id="del-rule-{{ $r->id }}" method="POST" action="{{ route('sdm.kebijakan.rule.destroy', $r->id) }}" onsubmit="return confirm('Hapus rule \'{{ $r->nama }}\'?');" class="hidden">
        @csrf @method('DELETE')
    </form>
@endforeach
@endsection
