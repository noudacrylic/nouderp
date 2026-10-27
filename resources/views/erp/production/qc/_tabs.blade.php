{{-- Tab internal QC. Acuan & Toleransi hanya untuk admin (role_gate production.qc-acuan). --}}
@php
    $tabQc = [
        ['label' => '⚖️ Timbang',      'route' => 'production.qc.index',            'pola' => 'production.qc.*',                'admin' => false],
        ['label' => '📐 Sampel Acuan', 'route' => 'production.qc-acuan.index',      'pola' => 'production.qc-acuan.index|production.qc-acuan.create|production.qc-acuan.edit', 'admin' => true],
        ['label' => '🎚️ Toleransi',    'route' => 'production.qc-acuan.toleransi',  'pola' => 'production.qc-acuan.toleransi*', 'admin' => true],
    ];
@endphp
<div class="flex items-center gap-1 mb-4 border-b border-gray-200">
    @foreach($tabQc as $t)
        @continue($t['admin'] && !user_can_access('production.qc-acuan'))
        @php $aktif = collect(explode('|', $t['pola']))->contains(fn ($p) => request()->routeIs($p)); @endphp
        <a href="{{ route($t['route']) }}"
           class="px-4 py-2 text-sm font-semibold -mb-px border-b-2 {{ $aktif ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700' }}">{{ $t['label'] }}</a>
    @endforeach
</div>
