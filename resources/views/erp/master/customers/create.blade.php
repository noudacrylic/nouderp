@extends('layouts.erp')

@section('content')

    <h1 class="text-lg font-semibold mb-6">
        Create Customer
    </h1>

    <form method="POST" action="{{ route('customers.store') }}">

        @csrf

        <div class="grid grid-cols-2 gap-6 max-w-3xl">

            <div>

                <label class="block text-sm mb-1">
                    Name
                </label>

                <input type="text" name="name" class="border rounded px-3 py-2 w-full" required>

            </div>


            <div>

                <label class="block text-sm mb-1">
                    Phone
                </label>

                <input type="text" name="phone" class="border rounded px-3 py-2 w-full">

            </div>


            <div>

                <label class="block text-sm mb-1">
                    Email
                </label>

                <input type="email" name="email" class="border rounded px-3 py-2 w-full">

            </div>


            <div>

                <label class="block text-sm mb-1">
                    Customer Type
                </label>

                <select name="customer_type" class="border rounded px-3 py-2 w-full">

                    <option value="regular">Regular</option>
                    <option value="marketplace">Marketplace</option>
                    <option value="reseller">Reseller</option>

                </select>

            </div>


            <div class="col-span-2">

                <label class="block text-sm mb-1">
                    Address
                </label>

                <textarea name="address" rows="3" class="border rounded px-3 py-2 w-full"
                    placeholder="Alamat lengkap customer"></textarea>

            </div>

        </div>


        <div class="mt-6">

            <button class="bg-blue-600 text-white px-4 py-2 rounded">
                Save Customer
            </button>

        </div>

    </form>

@endsection