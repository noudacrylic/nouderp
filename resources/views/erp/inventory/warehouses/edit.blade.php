@extends('layouts.erp')

@section('content')

    <div class="flex justify-center">

        <div class="w-full max-w-lg">

            <h1 class="text-xl font-semibold mb-6">
                Edit Warehouse
            </h1>

            <div class="bg-white shadow rounded-lg p-6">

                <form method="POST" action="{{ route('inventory.warehouses.update', $warehouse->id) }}">

                    @csrf
                    @method('PUT')

                    <div class="space-y-4">

                        <div>
                            <label class="block text-sm font-medium mb-1">Warehouse Name</label>
                            <input type="text" name="name" value="{{ $warehouse->name }}"
                                class="border rounded w-full px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>


                        <div>
                            <label class="block text-sm font-medium mb-1">Location</label>
                            <input type="text" name="location" value="{{ $warehouse->location }}"
                                class="border rounded w-full px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div class="pt-3 mt-1 border-t">
                            <p class="text-sm font-semibold text-gray-700 mb-1">Alamat Pengiriman</p>
                            <p class="text-xs text-gray-400 mb-3">Dipakai sebagai <b>asal pengiriman</b> saat cek ongkir & booking kurir.</p>

                            @include('erp.inventory.warehouses._shipping_fields', ['warehouse' => $warehouse])
                        </div>

                    </div>


                    <div class="mt-6 flex gap-3 justify-end">

                        <a href="{{ route('inventory.warehouses.index') }}"
                            class="px-4 py-2 border rounded hover:bg-gray-50">
                            Cancel
                        </a>

                        <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            Update Warehouse
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

@endsection