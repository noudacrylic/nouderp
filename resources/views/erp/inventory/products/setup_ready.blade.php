@extends('layouts.erp')

@section('content')

    <div class="max-w-7xl mx-auto space-y-6 pb-20">
        {{-- Progress Header --}}
        <div class="bg-blue-600 rounded-xl p-6 text-white shadow-lg overflow-hidden relative">
            <div class="relative z-10 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold">Step 2: Product Setup (Ready Stock)</h1>
                    <p class="text-blue-100 mt-1">Configure details for: <span
                            class="font-bold underline">{{ $product->name }}</span> ({{ $product->sku }})</p>
                </div>
                <span
                    class="bg-white/20 px-4 py-2 rounded-lg font-bold uppercase tracking-widest text-xs border border-white/20">
                    {{ str_replace('_', ' ', $product->sale_type) }}
                </span>
            </div>
            <div class="absolute top-0 right-0 -mr-16 -mt-16 w-64 h-64 bg-white/10 rounded-full blur-3xl"></div>
        </div>

        @include('erp.inventory.products.partials.product_info')

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            @include('erp.inventory.products.partials.units')
            @include('erp.inventory.products.partials.prices')
            @include('erp.inventory.products.partials.cost')
        </div>

        @include('erp.inventory.products.partials.opening_balance')

        @include('erp.inventory.products._shipping_dims')

        <div class="mt-12 text-end">
            <form action="{{ route('inventory.products.finish', $product->id) }}" method="POST">
                @csrf
                <button type="submit"
                    class="bg-green-600 text-white px-16 py-4 rounded-xl font-bold shadow-2xl hover:bg-green-700 active:transform active:scale-95 transition text-xl">
                    Selesai & Aktifkan Produk
                </button>
            </form>
        </div>
    </div>

@endsection

@section('scripts')
    @include('erp.inventory.products.partials.setup_js')
@endsection