@extends('layouts.erp')

@section('content')

    <h1 class="text-lg font-semibold mb-4">
        Edit Delivery
    </h1>

    <form method="POST" action="{{ route('sales.deliveries.update', $delivery->id) }}">

        @csrf
        @method('PUT')

        <div class="grid grid-cols-3 gap-6 max-w-3xl mb-6">

            <div>

                <label class="block text-sm mb-1">
                    Tanggal @if($delivery->status == 'posted') <span class="text-xs text-gray-400">(locked)</span> @endif
                </label>

                <input type="date" name="delivery_date" value="{{ $delivery->delivery_date }}"
                    class="border px-3 py-2 rounded w-full @if($delivery->status == 'posted') bg-gray-100 @endif"
                    @if($delivery->status == 'posted') readonly @endif>

            </div>


            <div>

                <label class="block text-sm mb-1">
                    Courier
                </label>

                <input type="text" name="courier_name" value="{{ $delivery->courier_name }}"
                    class="border px-3 py-2 rounded w-full" placeholder="JNE / J&T / SiCepat">

            </div>


            <div>

                <label class="block text-sm mb-1">
                    Tracking Number
                </label>

                <input type="text" name="tracking_number" value="{{ $delivery->tracking_number }}"
                    class="border px-3 py-2 rounded w-full" placeholder="Nomor Resi">

            </div>

        </div>


        <button class="bg-blue-600 text-white px-4 py-2 rounded">
            Perbarui Delivery
        </button>

    </form>

@endsection