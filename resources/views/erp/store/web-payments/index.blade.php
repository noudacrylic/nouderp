@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pembayaran Web — Transfer Bank + Kode Unik</h1>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            @foreach($statuses as $val => $label)
                <option value="{{ $val }}" @selected($status === $val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    @include('erp._partials.per-page-select')
    <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Order</th>
                <th class="px-3 py-2 text-left">Pelanggan</th>
                <th class="px-3 py-2 text-right">Kode</th>
                <th class="px-3 py-2 text-right">Nominal Transfer</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-left">Dibuat / Klaim</th>
                <th class="px-3 py-2 text-left">Konfirmasi</th>
                <th class="px-3 py-2 text-right w-40">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payments as $wp)
                @php $badge = $wp->statusLabel(); @endphp
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2 font-medium">{{ $wp->salesOrder->order_number ?? ('#' . $wp->sales_order_id) }}</td>
                    <td class="px-3 py-2">{{ $wp->salesOrder->customer->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-right font-mono">{{ $wp->unique_code }}</td>
                    <td class="px-3 py-2 text-right font-mono">Rp {{ number_format((float) $wp->expected_amount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center">
                        <span class="{{ $badge['class'] }} px-2 py-0.5 rounded text-xs">{{ $badge['label'] }}</span>
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-500">
                        <div>{{ optional($wp->created_at)->format('d/m/y H:i') }}</div>
                        @if($wp->buyer_claimed_at)
                            <div class="text-amber-600">klaim {{ $wp->buyer_claimed_at->format('d/m/y H:i') }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-500">
                        @if($wp->confirmed_at)
                            <div>{{ $wp->confirmed_at->format('d/m/y H:i') }}</div>
                            <div class="text-gray-400">via {{ $wp->confirmed_via }}{{ $wp->confirmedBy ? ' · ' . $wp->confirmedBy->name : '' }}</div>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        @if($wp->isOpen())
                            <div class="flex flex-col gap-1 items-end">
                                <form method="POST" action="{{ route('store.web-payments.confirm', $wp->id) }}"
                                      onsubmit="return confirm('Tandai LUNAS? Pembayaran (uang muka) akan diposting ke SO.')"
                                      class="flex gap-1 items-center">
                                    @csrf
                                    @if(count($accounts) > 1)
                                        <select name="account_key" class="border rounded px-1 py-1 text-xs" title="Rekening penerima">
                                            @foreach($accounts as $a)
                                                <option value="{{ $a['key'] }}">{{ $a['bank_name'] ?: 'Rekening' }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">✓ Lunas</button>
                                </form>
                                <form method="POST" action="{{ route('store.web-payments.cancel', $wp->id) }}"
                                      onsubmit="return confirm('Batalkan order ini? SO akan di-void & reservasi stok dilepas.')">
                                    @csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Batalkan</button>
                                </form>
                            </div>
                        @else
                            <span class="text-xs text-gray-300">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-500">Belum ada pembayaran web.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $payments->links() }}</div>
@endsection
