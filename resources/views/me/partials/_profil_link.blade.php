@php
    $tones = [
        'emerald' => 'bg-emerald-100 text-emerald-700',
        'indigo'  => 'bg-indigo-100 text-indigo-700',
        'green'   => 'bg-green-100 text-green-700',
    ];
    $iconCls = $tones[$tone ?? 'emerald'] ?? $tones['emerald'];
@endphp

<a href="{{ $href }}"
   class="flex items-center gap-3.5 bg-white rounded-2xl border border-slate-200 shadow-sm p-4 active:bg-slate-50 transition">
    <span class="w-11 h-11 shrink-0 rounded-xl flex items-center justify-center {{ $iconCls }}">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icon !!}</svg>
    </span>
    <div class="flex-1 min-w-0">
        <p class="text-sm font-bold text-slate-800">{{ $title }}</p>
        <p class="text-[11px] text-slate-400 mt-0.5">{{ $desc }}</p>
    </div>
    <svg class="w-5 h-5 text-slate-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
</a>
