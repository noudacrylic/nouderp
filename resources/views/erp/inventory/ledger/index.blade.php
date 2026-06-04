@extends('layouts.erp')

@section('content')
    <div class="p-6">
        {{-- Header ringkas --}}
        <div class="flex items-center justify-between gap-3 mb-4">
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-gray-800">Inventory Ledger</h1>
                <div class="text-xs text-gray-500 mt-0.5 truncate">{{ $product->sku }} — {{ $product->name }}</div>
            </div>
            <a href="/erp/inventory/stocks"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded text-sm transition whitespace-nowrap">
                &larr; Back to Stocks
            </a>
        </div>

        {{-- Filter (live) + ringkasan — satu bar --}}
        <div class="bg-white rounded shadow p-3 mb-4 flex flex-wrap gap-x-6 gap-y-3 items-end justify-between text-sm">
            <div class="flex flex-wrap gap-3 items-end">
                {{-- Tahun & Bulan --}}
                <form method="GET" class="flex gap-3 items-end">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Tahun</label>
                        <select name="year" onchange="this.form.submit()" class="border rounded px-2 py-1.5">
                            <option value="">— Semua —</option>
                            @foreach ($availableYears as $y)
                                <option value="{{ $y }}" {{ $selectedYear === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Bulan</label>
                        <select name="month" onchange="this.form.submit()" class="border rounded px-2 py-1.5">
                            <option value="">— Semua Bulan —</option>
                            @for ($m = 1; $m <= 12; $m++)
                                @php $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT); @endphp
                                <option value="{{ $mm }}" {{ $selectedMonth === $mm ? 'selected' : '' }}>
                                    {{ \Carbon\Carbon::create(2000, $m, 1)->translatedFormat('F') }}
                                </option>
                            @endfor
                        </select>
                    </div>
                </form>

                {{-- Rentang Tanggal (live) --}}
                <form method="GET" class="flex gap-3 items-end">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Dari Tanggal</label>
                        <input type="date" name="date_from" value="{{ request('date_from') }}" onchange="this.form.submit()"
                            class="border rounded px-2 py-1.5">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">s/d</label>
                        <input type="date" name="date_to" value="{{ request('date_to') }}" onchange="this.form.submit()"
                            class="border rounded px-2 py-1.5">
                    </div>
                    <a href="/erp/inventory/products/{{ $product->id }}/ledger"
                        class="text-gray-400 hover:text-gray-600 text-xs underline pb-2">Reset</a>
                </form>
            </div>

            {{-- Ringkasan --}}
            <div class="flex items-center gap-5">
                <div class="text-right">
                    <div class="text-[11px] uppercase tracking-wide text-gray-400">Masuk</div>
                    <div class="text-base font-bold text-green-600">{{ number_format($ledgers->sum('qty_in'), 2) }}</div>
                </div>
                <div class="text-right">
                    <div class="text-[11px] uppercase tracking-wide text-gray-400">Keluar</div>
                    <div class="text-base font-bold text-red-600">{{ number_format($ledgers->sum('qty_out'), 2) }}</div>
                </div>
                <div class="text-right">
                    <div class="text-[11px] uppercase tracking-wide text-gray-400">Saldo</div>
                    <div class="text-base font-bold text-blue-600">{{ number_format($ledgers->last()?->balance ?? 0, 2) }}</div>
                </div>
            </div>
        </div>

        {{-- Ledger Table --}}
        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead class="bg-gray-50 border-b text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-600">Tanggal</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-600">Tipe Transaksi</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-600">Referensi</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-600">Gudang</th>
                        <th class="px-3 py-2 text-right font-semibold text-gray-600">Qty Masuk</th>
                        <th class="px-3 py-2 text-right font-semibold text-gray-600">Qty Keluar</th>
                        <th class="px-3 py-2 text-right font-semibold text-gray-600">HPP / Unit</th>
                        <th class="px-3 py-2 text-right font-semibold text-gray-600">Nilai HPP</th>
                        <th class="px-3 py-2 text-right font-semibold text-gray-600">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ledgers as $ledger)
                        @php
                            $type = $ledger->transaction_type;
                            $badge = match(true) {
                                str_starts_with($type, 'opening')          => 'bg-green-100 text-green-700',
                                str_starts_with($type, 'purchase')         => 'bg-yellow-100 text-yellow-700',
                                str_starts_with($type, 'sale')             => 'bg-blue-100 text-blue-700',
                                str_starts_with($type, 'adjustment_in')    => 'bg-purple-100 text-purple-700',
                                str_starts_with($type, 'adjustment_out')   => 'bg-orange-100 text-orange-700',
                                str_starts_with($type, 'adjustment_void')  => 'bg-gray-200 text-gray-500',
                                str_starts_with($type, 'transfer_in')      => 'bg-teal-100 text-teal-700',
                                str_starts_with($type, 'transfer_out')     => 'bg-indigo-100 text-indigo-700',
                                str_starts_with($type, 'transfer_void')    => 'bg-gray-200 text-gray-500',
                                default                                    => 'bg-gray-100 text-gray-600',
                            };
                        @endphp
                        <tr class="border-t hover:bg-gray-50 transition">
                            <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                                {{ $ledger->created_at->format('d M Y H:i') }}
                            </td>
                            <td class="px-3 py-2">
                                <span class="px-2 py-1 rounded-full text-xs font-bold uppercase {{ $badge }}">
                                    {{ str_replace('_', ' ', $type) }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                @php
                                    $ref = $refMap[$ledger->transaction_type][$ledger->transaction_id] ?? null;
                                @endphp
                                @if($ref && $ref['url'])
                                    <a href="{{ $ref['url'] }}"
                                       class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800 hover:underline text-sm"
                                       title="{{ ucwords(str_replace('_', ' ', $ledger->transaction_type)) }}">
                                        {{ $ref['number'] }}
                                    </a>
                                @elseif($ref && $ref['number'])
                                    <span class="text-gray-700 text-sm">{{ $ref['number'] }}</span>
                                @else
                                    <span class="text-gray-400 text-xs">#{{ $ledger->transaction_id }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-600">
                                {{ optional($ledger->warehouse)->name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right font-semibold {{ $ledger->qty_in > 0 ? 'text-green-600' : 'text-gray-300' }}">
                                {{ $ledger->qty_in > 0 ? '+' . number_format($ledger->qty_in, 2) : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right font-semibold {{ $ledger->qty_out > 0 ? 'text-red-600' : 'text-gray-300' }}">
                                {{ $ledger->qty_out > 0 ? '−' . number_format($ledger->qty_out, 2) : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono text-xs {{ $ledger->hpp_per_unit !== null ? 'text-gray-700' : 'text-gray-300' }}">
                                {{ $ledger->hpp_per_unit !== null ? number_format($ledger->hpp_per_unit, 2) : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono text-xs {{ $ledger->hpp_total !== null ? 'text-gray-800 font-semibold' : 'text-gray-300' }}">
                                {{ $ledger->hpp_total !== null ? number_format($ledger->hpp_total, 2) : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right font-bold text-gray-800">
                                {{ number_format($ledger->balance, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-8 text-center text-gray-500 italic">
                                Belum ada riwayat transaksi untuk produk ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-xs text-gray-400 italic">
            * Sumber: <code>inventory_ledgers</code> (active). Legacy: <code>stock_ledgers</code> (deprecated, jangan dihapus dulu).
            HPP di-derive dari FIFO layer / cost layer (purchase, opening, sale, retur, produksi). Transfer &amp; adjustment belum dilengkapi HPP.
        </p>
    </div>
@endsection