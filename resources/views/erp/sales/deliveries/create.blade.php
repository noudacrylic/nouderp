@extends('layouts.erp')

@section('content')

    <h1 class="text-xl font-semibold mb-6">
        Create Delivery
    </h1>

    <form method="POST" action="{{ route('sales.deliveries.store') }}">
        @csrf

        <div class="grid grid-cols-3 gap-6 mb-6">

            <div x-data="soPicker()" class="relative">
                <label class="block text-sm mb-1">Sales Order</label>
                <input type="hidden" name="sales_order_id" :value="selectedId">
                <input type="text" x-model="query" @focus="open = true" autocomplete="off"
                    placeholder="Cari No SO atau customer..."
                    class="border px-3 py-2 rounded w-full focus:ring-blue-500 focus:border-blue-500 outline-none">
                <div x-show="open" x-cloak @click.outside="open = false"
                    class="absolute z-30 mt-1 w-full bg-white border rounded shadow-lg max-h-64 overflow-auto">
                    <template x-for="so in filtered" :key="so.id">
                        <button type="button" @click="select(so)"
                            class="block w-full text-left px-3 py-2 text-sm hover:bg-blue-50"
                            :class="so.id == selectedId ? 'bg-blue-50 font-semibold' : ''"
                            x-text="so.label"></button>
                    </template>
                    <div x-show="filtered.length === 0" class="px-3 py-2 text-sm text-gray-400">
                        Tidak ada SO yang cocok.
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm mb-1">Delivery Date</label>
                <input type="date" name="delivery_date" value="{{ date('Y-m-d') }}" class="border px-3 py-2 rounded w-full">
            </div>

            @php
                $isAmbil = isset($order) && $order->delivery_method === 'ambil_toko';
                $soCourier = isset($order)
                    ? trim(strtoupper((string)($order->shipping_courier_code ?? '')) . ' ' . (string)($order->shipping_service_name ?? ''))
                    : '';
            @endphp

            <div>
                <label class="block text-sm mb-1">Metode / Kurir</label>
                @if($isAmbil)
                    <div class="border px-3 py-2 rounded w-full bg-purple-50 text-purple-700 font-semibold">🏬 Ambil di Toko</div>
                    <input type="hidden" name="courier_name" value="Ambil di Toko">
                @else
                    <input type="text" name="courier_name" value="{{ $soCourier }}"
                        class="border px-3 py-2 rounded w-full" placeholder="E.g. JNE, J&T, etc">
                    @if($soCourier)
                        <p class="text-[11px] text-gray-400 mt-1">Otomatis dari Sales Order. Resi bisa di-booking otomatis setelah Simpan.</p>
                    @endif
                @endif
            </div>

        </div>

        @if($isAmbil)
            <div class="mb-6">
                <label class="block text-sm mb-1">Booking Code (Ambil di Toko) <span class="text-red-500">*</span></label>
                <input type="text" name="pickup_code" required autocomplete="off"
                    inputmode="numeric" maxlength="5"
                    class="border px-3 py-2 rounded w-full font-mono tracking-widest max-w-xs" placeholder="1234">
                <p class="text-[11px] text-gray-400 mt-1">Masukkan kode yang disebutkan customer. Harus cocok dengan kode di SO.</p>
            </div>
        @else
            <div class="mb-6">
                <label class="block text-sm mb-1">Tracking Number <span class="text-gray-400 text-xs">(opsional — bisa diisi otomatis dari booking resi)</span></label>
                <input type="text" name="tracking_number" class="border px-3 py-2 rounded w-full" placeholder="Nomor Resi">
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-50 border-l-4 border-red-400 p-4 rounded text-sm text-red-700 mb-4">
                {{ session('error') }}
            </div>
        @endif

        @if(isset($order) && !empty($items))
            <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b text-gray-600 font-semibold">
                        <tr>
                            <th class="text-left px-6 py-3">Product</th>
                            <th class="text-center px-4 py-3 w-24">SKU</th>
                            <th class="text-right px-4 py-3 w-24">Dipesan</th>
                            <th class="text-right px-4 py-3 w-28">Sudah Dikirim</th>
                            <th class="text-right px-4 py-3 w-24">Sisa</th>
                            <th class="text-right px-4 py-3 w-28">Tersedia (Stok)</th>
                            <th class="text-right px-6 py-3 w-32">Qty Kirim</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $index => $item)
                            <tr class="border-b hover:bg-gray-50 transition">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900">{{ $item['name'] }}</div>
                                    <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $item['product_id'] }}">
                                    <input type="hidden" name="items[{{ $index }}][sales_order_item_id]" value="{{ $item['sales_order_item_id'] }}">
                                </td>
                                <td class="px-4 py-4 text-center text-gray-500 font-mono text-xs">
                                    {{ $item['sku'] ?? '-' }}
                                </td>
                                <td class="px-4 py-4 text-right text-gray-700">{{ $item['ordered'] }}</td>
                                <td class="px-4 py-4 text-right text-gray-700">{{ $item['delivered'] }}</td>
                                <td class="px-4 py-4 text-right font-semibold text-gray-900">{{ $item['remaining'] }}</td>
                                <td class="px-4 py-4 text-right">
                                    <span class="{{ $item['available'] + 0.0001 < $item['remaining'] ? 'text-amber-600 font-semibold' : 'text-gray-700' }}">
                                        {{ $item['available'] }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <input type="number" step="any" min="0" max="{{ $item['remaining'] }}"
                                        name="items[{{ $index }}][qty]" value="{{ $item['qty'] }}"
                                        class="border rounded px-3 py-1 w-24 text-right focus:ring-blue-500 focus:border-blue-500 outline-none">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-6 py-3 text-xs text-gray-500 border-t bg-gray-50">
                    Qty kirim default = sisa. Jika <span class="text-amber-600 font-semibold">Tersedia (Stok)</span> lebih kecil dari qty kirim, barang preorder yang belum selesai produksi tetap bisa dikirim (stok minus) — HPP-nya ditunda dan dihitung saat invoice. Invoice diblok sampai produksinya selesai.
                </div>
            </div>

            <div class="flex gap-2 justify-end border-t pt-6">
                <a href="{{ route('sales.deliveries.index') }}"
                    class="px-4 py-2 border rounded text-sm font-bold text-gray-600 hover:bg-gray-50 transition">Batal</a>
                <button type="submit" name="action" value="draft"
                    class="px-4 py-2 border border-gray-300 rounded text-sm font-bold text-gray-700 bg-white hover:bg-gray-50 transition">
                    Draft
                </button>
                <button type="submit" name="action" value="post"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-bold transition">
                    Simpan
                </button>
                <button type="submit" name="action" value="print"
                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-bold transition">
                    Print
                </button>
            </div>
        @else
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded text-sm text-blue-700">
                Please select a Sales Order above to see the products and create a delivery.
            </div>
        @endif

    </form>

    @php
        $soPickerOrders = $salesOrders->map(fn($so) => [
            'id'     => $so->id,
            'label'  => $so->order_number . ' - ' . ($so->customer->name ?? '-'),
            'search' => mb_strtolower($so->order_number . ' ' . ($so->customer->name ?? '')),
        ])->values();
        $soPickerQuery = isset($order) ? ($order->order_number . ' - ' . ($order->customer->name ?? '-')) : '';
        $soPickerSelected = $order->id ?? '';
    @endphp
    <style>[x-cloak]{ display:none !important; }</style>
    <script>
        function soPicker() {
            return {
                open: false,
                query: @json($soPickerQuery),
                selectedId: @json($soPickerSelected),
                orders: @json($soPickerOrders),
                get filtered() {
                    const q = (this.query || '').toLowerCase().trim();
                    if (!q) return this.orders;
                    return this.orders.filter(o => o.search.includes(q));
                },
                select(so) {
                    this.selectedId = so.id;
                    this.query = so.label;
                    this.open = false;
                    // Navigasi agar item SO ter-muat (qty per baris dihitung server-side).
                    window.location.href = '{{ url('erp/sales/deliveries/create') }}/' + so.id;
                },
            };
        }
    </script>

@endsection