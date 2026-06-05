@props(['title', 'customer', 'warehouse', 'dateField', 'dateValue', 'deliveries', 'remainingAdvance'])

<div class="flex justify-between items-center mb-6">
    <h2 class="text-xl font-bold">{{ $title ?? 'Transaction' }}</h2>
</div>

@if(isset($customer))
    <div class="grid grid-cols-3 gap-6 mb-6">
        <div>
            <label class="block text-sm font-medium text-gray-700">Pelanggan</label>
            <div class="mt-1 p-2 border border-gray-300 rounded bg-gray-50 text-gray-900">
                {{ $customer->name ?? '-' }}
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Gudang</label>
            <div class="mt-1 p-2 border border-gray-300 rounded bg-gray-50 text-gray-900">
                {{ $warehouse->name ?? '-' }}
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Tanggal Faktur</label>
            <input type="date" name="{{ $dateField ?? 'invoice_date' }}" value="{{ $dateValue ?? date('Y-m-d') }}"
                class="mt-1 block w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
        </div>
    </div>

    <hr class="my-6 border-gray-200">
@endif

<div class="grid grid-cols-2 gap-6">
    @if(isset($deliveries))
        <div>
            <label class="block text-sm font-medium text-gray-700">Delivery (SJ)</label>
            <select name="delivery_id"
                class="mt-1 block w-full border border-gray-300 rounded px-3 py-2 bg-white focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                <option value="">Tanpa Delivery</option>
                @foreach($deliveries as $delivery)
                    <option value="{{ $delivery->id }}">
                        {{ $delivery->delivery_number }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif

    @if(isset($remainingAdvance) && $remainingAdvance > 0)
        <div>
            <label class="block text-sm font-medium text-gray-700">Apply Advance</label>
            <input type="number" step="0.01" name="apply_advance" max="{{ $remainingAdvance }}" value="0"
                class="mt-1 block w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">

            <div class="text-xs text-gray-500 mt-1">
                Max: {{ number_format($remainingAdvance, 2) }}
            </div>
        </div>
    @endif
</div>