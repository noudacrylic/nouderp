<div class="flex justify-between items-center mb-6">

    <div>
        <h2 class="text-xl font-bold">
            {{ $title }}
        </h2>

        @isset($status)
            <div class="text-gray-500">
                Status : {{ strtoupper($status) }}
            </div>
        @endisset
    </div>

    @isset($reference)
        <div class="bg-blue-50 border border-blue-200 px-4 py-2 rounded">
            Referensi : {{ $reference }}
        </div>
    @endisset

</div>