@php
    $isRule       = request()->routeIs('sdm.kebijakan.rule.*') || request()->routeIs('sdm.kebijakan.index');
    $isKolom      = request()->routeIs('sdm.kebijakan.kolom.*');
@endphp

<div class="bg-gray-50 rounded border mb-4 flex text-sm overflow-x-auto">
    <a href="{{ route('sdm.kebijakan.rule.index') }}"
       class="px-4 py-2 whitespace-nowrap {{ $isRule ? 'bg-white border-b-2 border-emerald-600 text-emerald-700 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
        Rule Kebijakan
    </a>
    <a href="{{ route('sdm.kebijakan.kolom.index') }}"
       class="px-4 py-2 whitespace-nowrap {{ $isKolom ? 'bg-white border-b-2 border-emerald-600 text-emerald-700 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
        Kolom Akibat
    </a>
</div>
