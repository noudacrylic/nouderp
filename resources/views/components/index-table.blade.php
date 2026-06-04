<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    {{-- Header / Title & Global Actions --}}
    @isset($title)
    <div class="flex justify-between items-center p-4 border-b border-gray-100">
        <h2 class="text-xl font-bold text-gray-800">{{ $title }}</h2>
        @isset($actions)
            <div class="flex gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>
    @endisset

    {{-- Filter Bar --}}
    @isset($filters)
    <div class="bg-gray-50/50 p-4 border-b border-gray-100">
        {{ $filters }}
    </div>
    @endisset

    {{-- Table Area --}}
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse table-compact">
            <thead>
                <tr class="bg-gray-50/80 border-b border-gray-100">
                    {{ $thead }}
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                {{ $slot }}
            </tbody>
        </table>
    </div>

    {{-- Footer / Pagination --}}
    @isset($footer)
    <div class="p-4 bg-gray-50/30 border-t border-gray-100">
        {{ $footer }}
    </div>
    @endisset
</div>
