@props(['title' => ''])

<a {{ $attributes->merge(['class' => 'p-1.5 bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 rounded-lg shadow-sm transition-all active:scale-95 flex items-center justify-center']) }} title="{{ $title }}">
    {{ $slot }}
</a>
