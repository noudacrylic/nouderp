<div class="mb-6">
    {{-- TITLE & GLOBAL ACTIONS --}}
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-2xl font-bold text-gray-800">{{ $title }}</h2>
        @isset($actions)
            <div class="flex gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>

    {{-- FILTER BAR --}}
    @isset($filters)
    <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6">
        {{ $filters }}
    </div>
    @endisset

    {{-- TABLE --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse table-compact">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        {{ $thead }}
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    {{ $slot }}
                </tbody>
            </table>
        </div>

        @isset($footer)
        <div class="p-4 bg-gray-50/30 border-t border-gray-100">
            {{ $footer }}
        </div>
        @endisset
    </div>
</div>
