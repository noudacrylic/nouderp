@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Edit Retur — {{ $return->return_number }}</h1>

@if($errors->any())
    <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm mb-3">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

<form method="POST" action="{{ route('purchasing.returns.update', $return->id) }}">
    @csrf @method('PUT')

    <div class="bg-white rounded shadow p-4 mb-4">
        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-sm mb-1">Tanggal Retur <span class="text-red-500">*</span></label>
                <input type="date" name="return_date" class="border rounded px-3 py-2 w-full" value="{{ $return->return_date->format('Y-m-d') }}" required>
            </div>
        </div>
        <div class="mt-3">
            <label class="block text-sm mb-1">Catatan</label>
            <textarea name="notes" rows="2" class="border rounded px-3 py-2 w-full">{{ $return->notes }}</textarea>
        </div>
    </div>

    <div class="bg-white rounded shadow p-4 mb-4">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b text-gray-600">
                <tr>
                    <th class="px-2 py-2 text-left">Produk</th>
                    <th class="px-2 py-2 text-right w-32">Biaya</th>
                    <th class="px-2 py-2 text-right w-28">Qty</th>
                    <th class="px-2 py-2 text-right w-32">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($return->items as $idx => $i)
                    <tr class="border-b">
                        <td class="px-2 py-1">
                            <input type="hidden" name="items[{{ $idx }}][purchase_invoice_item_id]" value="{{ $i->purchase_invoice_item_id }}">
                            {{ $i->product->name ?? '?' }}
                        </td>
                        <td class="px-2 py-1 text-right">{{ number_format($i->unit_cost, 2, ',', '.') }}</td>
                        <td class="px-2 py-1"><input type="number" step="0.01" min="0.01" name="items[{{ $idx }}][qty]" value="{{ $i->qty }}" class="border rounded px-2 py-1 w-full text-right" required></td>
                        <td class="px-2 py-1 text-right">{{ number_format($i->subtotal, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="flex gap-2">
        <button class="bg-blue-600 text-white px-4 py-2 rounded">Simpan</button>
        <a href="{{ route('purchasing.returns.show', $return->id) }}" class="px-4 py-2 border rounded">Batal</a>
    </div>
</form>
@endsection
