@php
    $status = strtoupper($status->value ?? $status ?? 'draft');
    $class = match($status) {
        'DRAFT' => 'bg-gray-100 text-gray-700',
        'CONFIRMED', 'POSTED' => 'bg-green-100 text-green-700',
        'CANCELLED', 'VOID' => 'bg-red-100 text-red-700',
        default => 'bg-blue-100 text-blue-700'
    };
@endphp

<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10.5px] font-bold {{ $class }} whitespace-nowrap">
    {{ $status }}
</span>
