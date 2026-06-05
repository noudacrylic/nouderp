@extends('layouts.erp')

@section('content')

    <div class="flex justify-center">

        <div class="w-full max-w-lg">

            <h1 class="text-xl font-semibold mb-6">
                Tambah Gudang
            </h1>

            <div class="bg-white shadow rounded-lg p-6">

                <form method="POST" action="{{ route('inventory.warehouses.store') }}">

                    @csrf

                    <div class="space-y-5">

                        <div>
                            <label class="block text-sm font-medium mb-1">
                                Nama Gudang
                            </label>

                            <input type="text" name="name" required class="w-full border rounded px-3 py-2">
                        </div>


                        <div>
                            <label class="block text-sm font-medium mb-1">
                                Lokasi
                            </label>

                            <input type="text" name="location" value="{{ old('location') }}" class="w-full border rounded px-3 py-2">
                        </div>

                        <div class="flex items-center gap-2">

                            <input type="checkbox" name="is_sellable" value="1" checked>

                            <label class="text-sm">
                                Gudang Bisa Dijual
                            </label>

                        </div>

                        <div class="pt-3 mt-1 border-t">
                            <p class="text-sm font-semibold text-gray-700 mb-1">Alamat Pengiriman</p>
                            <p class="text-xs text-gray-400 mb-3">Dipakai sebagai <b>asal pengiriman</b> saat cek ongkir & booking kurir.</p>

                            @include('erp.inventory.warehouses._shipping_fields', ['warehouse' => null])
                        </div>

                    </div>


                    <div class="mt-6 flex justify-end gap-3">

                        <a href="{{ route('inventory.warehouses.index') }}" class="px-4 py-2 border rounded">
                            Batal
                        </a>

                        <button type="submit" class="bg-green-600 text-white px-5 py-2 rounded">
                            Simpan
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

@endsection