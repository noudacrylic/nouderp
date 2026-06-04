@php
    $statusData = $statusData ?? [
        'label' => 'NOT INVOICED',
        'class' => 'bg-red-100 text-red-700'
    ];
@endphp

<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold {{ $statusData['class'] }} whitespace-nowrap">
    ● {{ $statusData['label'] }}
</span>
