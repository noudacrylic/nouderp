@php
    $navItems = [
        ['route' => 'me.home',    'label' => 'Beranda', 'icon' => 'home',     'match' => ['me.home']],
        ['route' => 'me.absensi', 'label' => 'Absensi', 'icon' => 'calendar', 'match' => ['me.absensi']],
        ['route' => 'me.izin',    'label' => 'Izin',    'icon' => 'pencil',   'match' => ['me.izin', 'me.izin.*']],
        // Profil = hub Data Pribadi + Cuti/SP + Slip → tetap aktif saat di sub-halaman tsb.
        ['route' => 'me.profil',  'label' => 'Profil',  'icon' => 'user',     'match' => ['me.profil', 'me.profil.*', 'me.cuti', 'me.slip', 'me.slip.*']],
    ];
    $icons = [
        'home'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3v-6h4v6h3a1 1 0 001-1V10"/>',
        'calendar'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>',
        'pencil'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
        'user'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
    ];
@endphp

<nav class="bottom-nav fixed bottom-0 inset-x-0 z-40 max-w-md mx-auto bg-white border-t border-slate-200 shadow-[0_-4px_16px_rgba(0,0,0,0.04)]">
    <div class="grid grid-cols-{{ count($navItems) }}">
        @foreach ($navItems as $item)
            @php $active = request()->routeIs($item['match'] ?? $item['route']); @endphp
            <a href="{{ route($item['route']) }}"
               class="flex flex-col items-center justify-center gap-1 py-2.5 text-[11px] font-semibold transition
                      {{ $active ? 'text-emerald-700' : 'text-slate-400' }}">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons[$item['icon']] !!}</svg>
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
