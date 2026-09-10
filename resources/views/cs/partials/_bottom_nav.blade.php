@php
    /* Tiga item, bukan lima. Produk / Ongkir / Pesanan sengaja TIDAK di sini:
       alat itu dipakai di dalam satu percakapan, dan menaruhnya di nav global
       memaksa CS memilih chat dulu setelah menekannya — satu langkah mundur.
       Tempatnya di lembar geser dari layar dialog chat. */
    $navItems = [
        ['route' => 'cs.chat',       'label' => 'Chat',       'icon' => 'chat',  'match' => ['cs.chat', 'cs.chat.*']],
        ['route' => 'cs.notifikasi', 'label' => 'Notifikasi', 'icon' => 'bell',  'match' => ['cs.notifikasi']],
        ['route' => 'cs.profil',     'label' => 'Profil',     'icon' => 'user',  'match' => ['cs.profil']],
    ];
    $icons = [
        'chat' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>',
        'bell' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>',
        'user' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
    ];
    /* Dihitung di sini kalau pemanggilnya tidak mengoper: layar chat memakai
       data ruang kerja CRM yang tidak tahu-menahu soal lonceng, dan lencana
       yang cuma muncul di dua dari tiga layar lebih membingungkan daripada
       tidak ada sama sekali. */
    $belumDibaca = $navBelumDibaca
        ?? \App\Models\ErpNotification::milik((int) auth()->id())->belumDibaca()->count();
@endphp

<nav class="bottom-nav shrink-0 bg-white border-t border-slate-200 shadow-[0_-4px_16px_rgba(0,0,0,0.04)]">
    <div class="grid grid-cols-{{ count($navItems) }}">
        @foreach ($navItems as $item)
            @php $active = request()->routeIs($item['match'] ?? $item['route']); @endphp
            <a href="{{ route($item['route']) }}"
               class="relative flex flex-col items-center justify-center gap-1 py-2.5 text-[11px] font-semibold transition
                      {{ $active ? 'text-teal-700' : 'text-slate-400' }}">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons[$item['icon']] !!}</svg>
                {{ $item['label'] }}
                @if ($item['icon'] === 'bell' && $belumDibaca > 0)
                    <span class="absolute top-1.5 right-[calc(50%-1.6rem)] min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">
                        {{ $belumDibaca > 99 ? '99+' : $belumDibaca }}
                    </span>
                @endif
            </a>
        @endforeach
    </div>
</nav>
