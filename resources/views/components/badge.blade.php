@props(['type' => 'secondary'])

@php
    $class = match($type) {
        'success' => 'bg-green-100 text-green-700',
        'danger' => 'bg-red-100 text-red-700',
        'warning' => 'bg-amber-100 text-amber-700',
        'info' => 'bg-blue-100 text-blue-700',
        'secondary' => 'bg-gray-100 text-gray-700',
        default => 'bg-gray-100 text-gray-700'
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider $class"]) }}>
    {{ $slot }}
</span>
