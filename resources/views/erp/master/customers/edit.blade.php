@extends('layouts.erp')

@section('content')

    <h1 class="text-lg font-semibold mb-6">
        Edit Customer
    </h1>

    <form method="POST" action="{{ route('customers.update', $customer->id) }}">

        @csrf
        @method('PUT')

        <div class="grid grid-cols-2 gap-6 max-w-3xl">

            <div>

                <label class="block text-sm mb-1">
                    Name
                </label>

                <input type="text" name="name" value="{{ $customer->name }}" class="border rounded px-3 py-2 w-full"
                    required>

            </div>


            <div>

                <label class="block text-sm mb-1">
                    Phone
                </label>

                <input type="text" name="phone" value="{{ $customer->phone }}" class="border rounded px-3 py-2 w-full">

                <label class="flex items-start gap-2 mt-2 text-sm text-gray-700">
                    <input type="checkbox" name="wa_opt_in" value="1" class="mt-1" @checked(old('wa_opt_in', $customer->wa_opt_in))>
                    <span>
                        Bersedia menerima notifikasi pesanan lewat WhatsApp
                        @if ($customer->wa_opt_in_at)
                            <span class="block text-xs text-gray-500">
                                Disetujui {{ $customer->wa_opt_in_at->translatedFormat('d M Y H:i') }}
                                @if ($customer->wa_opt_in_source) &middot; {{ str_replace('_', ' ', $customer->wa_opt_in_source) }} @endif
                            </span>
                        @else
                            <span class="block text-xs text-gray-500">Centang hanya bila pelanggan benar-benar menyetujui — tanpa ini, notifikasi tidak dikirim.</span>
                        @endif
                    </span>
                </label>

            </div>


            <div>

                <label class="block text-sm mb-1">
                    Email
                </label>

                <input type="email" name="email" value="{{ $customer->email }}" class="border rounded px-3 py-2 w-full">

            </div>


            <div>

                <label class="block text-sm mb-1">
                    Customer Type
                </label>

                <select name="customer_type" class="border rounded px-3 py-2 w-full">

                    <option value="regular" {{ $customer->customer_type == 'regular' ? 'selected' : '' }}>
                        Regular
                    </option>

                    <option value="marketplace" {{ $customer->customer_type == 'marketplace' ? 'selected' : '' }}>
                        Marketplace
                    </option>

                    <option value="reseller" {{ $customer->customer_type == 'reseller' ? 'selected' : '' }}>
                        Reseller
                    </option>

                </select>

            </div>


            <div class="col-span-2">

                <label class="block text-sm mb-1">
                    Alamat (umum / penagihan)
                </label>

                <textarea name="address" rows="3"
                    class="border rounded px-3 py-2 w-full">{{ old('address', $customer->address) }}</textarea>

            </div>

            @include('erp.master.customers._shipping_fields', ['customer' => $customer])

        </div>


        <div class="mt-6">

            <button class="bg-blue-600 text-white px-4 py-2 rounded">
                Update Customer
            </button>

        </div>

    </form>

@endsection