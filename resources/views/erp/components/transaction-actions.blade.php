<div class="flex justify-between items-center mb-4">
    <div class="flex gap-2">

        @if(!empty($indexUrl))
            <a href="{{ $indexUrl }}" class="bg-gray-500 text-white px-3 py-1 rounded">
                Daftar
            </a>
        @endif

        @if($status == 'draft')

            @if(!empty($editUrl))
                <a href="{{ $editUrl }}" class="bg-blue-600 text-white px-3 py-1 rounded">
                    Edit
                </a>
            @endif

            @if(!empty($postUrl))
                <form method="POST" action="{{ $postUrl }}">
                    @csrf
                    <button class="bg-green-600 text-white px-3 py-1 rounded">
                        Post
                    </button>
                </form>
            @endif

            @if(!empty($cancelUrl))
                <form method="POST" action="{{ $cancelUrl }}">
                    @csrf
                    <button class="bg-red-600 text-white px-3 py-1 rounded">
                        Batal
                    </button>
                </form>
            @endif

        @endif

        @if(!empty($orderUrl))
            <a href="{{ $orderUrl }}" class="bg-green-600 text-white px-3 py-1 rounded">
                + Buat Baru
            </a>
        @endif

        @if($status == 'posted' || $status == 'confirmed')

            @if(!empty($advanceUrl))
                <a href="{{ $advanceUrl }}" class="bg-yellow-500 text-white px-3 py-1 rounded">
                    Buat Uang Muka
                </a>
            @endif

            @if(!empty($deliveryUrl))
                <a href="{{ $deliveryUrl }}" class="bg-purple-600 text-white px-3 py-1 rounded">
                    Buat Pengiriman
                </a>
            @endif

            @if(!empty($invoiceUrl))
                <a href="{{ $invoiceUrl }}" class="bg-blue-600 text-white px-3 py-1 rounded">
                    Buat Faktur
                </a>
            @endif

        @endif

    </div>

    @if(!empty($createNewUrl))
        <a href="{{ $createNewUrl }}" class="bg-gray-700 text-white px-4 py-2 rounded">
            + Buat Baru
        </a>
    @endif
</div>