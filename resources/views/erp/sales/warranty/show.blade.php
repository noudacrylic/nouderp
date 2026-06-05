@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    {{-- Breadcrumb + Header --}}
    <div class="flex justify-between items-start mb-6">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-400 font-medium mb-1">
                <a href="{{ route('sales.warranty.index') }}" class="hover:text-blue-600 transition">Garansi</a>
                <span>›</span>
                <span class="text-gray-600">{{ $warranty->warranty_number }}</span>
            </div>
            <h1 class="text-xl font-bold text-gray-800 flex items-center gap-3">
                {{ $warranty->warranty_number }}
                @php
                    $badgeMap = [
                        'draft'    => 'bg-amber-100 text-amber-700 border-amber-200',
                        'received' => 'bg-blue-100 text-blue-700 border-blue-200',
                        'posted'   => 'bg-blue-100 text-blue-700 border-blue-200',
                        'repaired' => 'bg-indigo-100 text-indigo-700 border-indigo-200',
                        'shipped'  => 'bg-green-100 text-green-700 border-green-200',
                    ];
                @endphp
                <span class="text-sm px-3 py-1 rounded-full font-black uppercase tracking-widest border {{ $badgeMap[$warranty->status] ?? 'bg-gray-100 text-gray-500 border-gray-200' }}">
                    {{ $warranty->status_label }}
                </span>
            </h1>
            <div class="flex items-center gap-4 mt-1.5 text-sm text-gray-500">
                <span>Pelanggan: <strong class="text-gray-700">{{ $warranty->customer->name }}</strong></span>
                <span>|</span>
                <span>Tanggal: <strong class="text-gray-700">{{ $warranty->warranty_date->format('d/m/Y') }}</strong></span>
                <span>|</span>
                <span>Jenis:
                    <span class="inline-flex items-center gap-1 bg-orange-100 text-orange-700 px-2 py-0.5 rounded text-xs font-black">Perbaikan</span>
                </span>
                @if($warranty->invoice)
                    <span>|</span>
                    <span>Faktur: <a href="{{ route('sales.invoices.show', $warranty->invoice_id) }}" class="text-blue-600 font-bold hover:underline">{{ $warranty->invoice->invoice_number }}</a></span>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if(($warranty->type ?? 'repair') !== 'replacement' && in_array($warranty->status, ['posted', 'repaired'], true))
                <a href="{{ route('production.orders.create', [
                        'type'               => 'repair',
                        'repair_source_type' => 'warranty',
                        'repair_source_id'   => $warranty->id,
                        'repair_ref'         => $warranty->warranty_number,
                    ]) }}"
                   class="inline-flex items-center gap-2 bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-xl text-sm font-bold transition-all"
                   title="Buat Order Produksi perbaikan dari garansi ini">
                    + OP Perbaikan
                </a>
            @endif
            <a href="{{ route('sales.warranty.index') }}"
               class="inline-flex items-center gap-2 border border-gray-200 text-gray-600 hover:bg-gray-50 bg-white px-4 py-2 rounded-xl text-sm font-semibold transition-all">
                ← Kembali
            </a>
        </div>
    </div>


    <div class="grid grid-cols-12 gap-5">

        {{-- MAIN CONTENT --}}
        <div class="col-span-8 space-y-5">

            {{-- Timeline --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <h3 class="font-bold text-gray-600 text-sm mb-4">Progress Garansi</h3>
                @php
                    $steps = [
                        ['key' => 'draft',    'label' => 'Draft Dibuat'],
                        ['key' => 'posted',   'label' => 'Diterima & Diposting'],
                        ['key' => 'repaired', 'label' => 'Selesai Diperbaiki'],
                        ['key' => 'shipped',  'label' => 'Dikirim ke Customer'],
                    ];

                    $statusOrder  = ['draft' => 0, 'received' => 1, 'posted' => 1, 'repaired' => 2, 'shipped' => 3];
                    $currentOrder = $statusOrder[$warranty->status] ?? 0;
                @endphp
                <div class="flex items-center gap-0">
                    @foreach($steps as $si => $step)
                        @php
                            $stepOrder = $statusOrder[$step['key']] ?? $si;
                            $done = $currentOrder >= $stepOrder;
                        @endphp
                        <div class="flex items-center {{ $si < count($steps) - 1 ? 'flex-1' : '' }}">
                            <div class="flex flex-col items-center">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-black border-2
                                    {{ $done ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-gray-200 text-gray-400' }}">
                                    {{ $si + 1 }}
                                </div>
                                <span class="text-[10px] mt-1 font-bold {{ $done ? 'text-blue-600' : 'text-gray-400' }} text-center whitespace-nowrap">
                                    {{ $step['label'] }}
                                </span>
                            </div>
                            @if($si < count($steps) - 1)
                                <div class="flex-1 h-0.5 mx-2 mb-4 {{ $done ? 'bg-blue-600' : 'bg-gray-200' }}"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Barang Dilaporkan --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
                    <span class="w-6 h-6 bg-gray-100 text-gray-600 rounded-lg flex items-center justify-center text-xs font-black">1</span>
                    Barang Dilaporkan Bermasalah
                </h3>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100">
                            <th class="text-left pb-2 font-black text-gray-400 text-[10px] uppercase tracking-widest">SKU</th>
                            <th class="text-left pb-2 font-black text-gray-400 text-[10px] uppercase tracking-widest">Produk</th>
                            <th class="text-center pb-2 font-black text-gray-400 text-[10px] uppercase tracking-widest">Qty</th>
                            <th class="text-left pb-2 font-black text-gray-400 text-[10px] uppercase tracking-widest">Masalah</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($warranty->items as $item)
                            <tr>
                                <td class="py-2.5 font-bold text-blue-600 text-xs">{{ $item->product->sku ?? '-' }}</td>
                                <td class="py-2.5 text-gray-700">{{ $item->product->name ?? '-' }}</td>
                                <td class="py-2.5 text-center font-bold">{{ $item->qty }}</td>
                                <td class="py-2.5 text-gray-500 text-xs">{{ $item->issue_description ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($warranty->issue_description)
                    <div class="mt-4 p-3 bg-gray-50 rounded-xl text-sm text-gray-600">
                        <span class="font-bold text-gray-500">Deskripsi Masalah:</span> {{ $warranty->issue_description }}
                    </div>
                @endif
            </div>

            {{-- SJ Garansi --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
                    <span class="w-6 h-6 bg-gray-100 text-gray-600 rounded-lg flex items-center justify-center text-xs font-black">2</span>
                    SJ Garansi (Pengiriman Keluar)
                </h3>

                @if(!$warranty->delivery)
                    {{-- Belum ada SJ --}}
                    @php
                        $canCreateDelivery = ($warranty->status === 'repaired');
                    @endphp

                    @if($canCreateDelivery)
                        <form action="{{ route('sales.warranty.delivery.create', $warranty->id) }}" method="POST"
                              class="space-y-4" x-data="{ shipping: 0, service: 0 }"
                              onsubmit="return confirm('Buat SJ Garansi untuk barang di atas?')">
                            @csrf
                            <input type="hidden" name="delivery_date" value="{{ date('Y-m-d') }}">

                            {{-- Barang yang dikirim — otomatis dari "Barang Dilaporkan Bermasalah" --}}
                            <div>
                                <p class="text-xs font-bold text-gray-500 mb-2 uppercase">Barang yang dikirim ke customer</p>
                                <div class="border border-gray-100 rounded-xl divide-y divide-gray-50">
                                    @forelse($warranty->items as $it)
                                        <div class="flex items-center justify-between px-3 py-2 text-sm">
                                            <span class="text-gray-700">
                                                <span class="font-bold text-blue-600">{{ $it->product->sku ?? '-' }}</span>
                                                <span class="ml-1">{{ $it->product->name ?? '-' }}</span>
                                            </span>
                                            <span class="font-bold">{{ rtrim(rtrim(number_format($it->qty, 4, '.', ''), '0'), '.') }}</span>
                                        </div>
                                    @empty
                                        <div class="px-3 py-2 text-sm text-gray-400">Tidak ada barang.</div>
                                    @endforelse
                                </div>
                            </div>

                            {{-- Biaya Garansi --}}
                            <div class="border-t border-gray-100 pt-3 space-y-3">
                                <p class="text-xs font-bold text-gray-500 uppercase">Biaya Garansi (opsional)</p>

                                {{-- Cek ongkir + alamat (mengisi Ongkos Kirim di bawah) --}}
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1">🚚 Cek Ongkir Pengiriman Balik</label>
                                    @include('erp._partials.cek-ongkir-widget', [
                                        'custId'    => $warranty->customer_id,
                                        'whId'      => $warranty->warehouse_id,
                                        'targetSel' => 'input[name=shipping_cost]',
                                    ])
                                </div>

                                {{-- Ongkos kirim --}}
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1">Ongkos Kirim (Rp)</label>
                                    <input type="number" name="shipping_cost" min="0" step="1" x-model.number="shipping" placeholder="0"
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    <div class="flex flex-col gap-1.5 mt-2 text-xs" x-show="shipping > 0" x-cloak>
                                        <label class="flex items-center gap-1.5 cursor-pointer">
                                            <input type="radio" name="shipping_paid_by" value="customer" checked class="accent-blue-600">
                                            Dibayar customer lewat kita → <span class="font-semibold">Titipan Ongkir (1203)</span>
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer">
                                            <input type="radio" name="shipping_paid_by" value="company" class="accent-blue-600">
                                            Kita yang bayar → <span class="font-semibold">Beban Garansi (6106)</span>
                                        </label>
                                    </div>
                                </div>

                                {{-- Jasa garansi --}}
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1">Jasa Garansi (Rp)</label>
                                    <input type="number" name="service_fee" min="0" step="1" x-model.number="service" placeholder="0"
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    <p class="text-[10px] text-gray-400 mt-1">Isi bila ada biaya tambahan yang dibebankan ke customer → masuk <span class="font-semibold">Pendapatan Jasa (4010)</span>. Kosongkan jika tidak ada.</p>
                                </div>

                                {{-- Akun Kas/Bank (muncul jika ada biaya) --}}
                                <div x-show="shipping > 0 || service > 0" x-cloak>
                                    <label class="block text-xs font-bold text-gray-500 mb-1">Akun Kas/Bank</label>
                                    <select name="fee_cash_account_id"
                                            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                        <option value="">— Pilih Kas/Bank —</option>
                                        @foreach($cashAccounts as $acc)
                                            <option value="{{ $acc->id }}">{{ $acc->code }} — {{ $acc->name }}</option>
                                        @endforeach
                                    </select>
                                    <p class="text-[10px] text-gray-400 mt-1">Penerimaan jasa & ongkir customer masuk sini; ongkir yang kita tanggung keluar dari sini.</p>
                                </div>
                            </div>

                            <button type="submit"
                                    class="w-full bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition">
                                Buat SJ Garansi
                            </button>
                        </form>
                    @else
                        <div class="text-sm text-gray-400 text-center py-6">
                            Tandai perbaikan selesai dulu sebelum membuat SJ pengiriman balik.
                        </div>
                    @endif

                @elseif($warranty->delivery->status === 'draft')
                    {{-- SJ ada, belum posted --}}
                    <div class="border border-amber-200 bg-amber-50 rounded-xl p-4 mb-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-bold text-amber-700">{{ $warranty->delivery->delivery_number }}</span>
                            <span class="text-xs bg-amber-200 text-amber-700 px-2 py-0.5 rounded font-bold">DRAFT</span>
                        </div>
                        <table class="w-full text-sm mb-3">
                            <thead>
                                <tr class="border-b border-amber-200">
                                    <th class="text-left pb-1.5 text-[10px] font-black text-amber-600 uppercase">Produk</th>
                                    <th class="text-center pb-1.5 text-[10px] font-black text-amber-600 uppercase">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($warranty->delivery->items as $di)
                                    <tr class="border-b border-amber-100">
                                        <td class="py-1.5 text-gray-700">{{ $di->product->name ?? '-' }}</td>
                                        <td class="py-1.5 text-center font-bold">{{ $di->qty }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <form action="{{ route('sales.warranty.delivery.post', [$warranty->id, $warranty->delivery->id]) }}" method="POST">
                            @csrf
                            <button type="submit"
                                    onclick="return confirm('Post SJ Garansi ini? Stok akan dikurangi (tidak ada jurnal).')"
                                    class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-xl text-sm font-bold transition">
                                Post SJ Garansi
                            </button>
                        </form>
                    </div>

                @else
                    {{-- SJ posted --}}
                    <div class="border border-green-200 bg-green-50 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-bold text-green-700">{{ $warranty->delivery->delivery_number }}</span>
                            <span class="text-xs bg-green-200 text-green-700 px-2 py-0.5 rounded font-black">POSTED ✓</span>
                        </div>
                        <table class="w-full text-sm mb-3">
                            <thead>
                                <tr class="border-b border-green-200">
                                    <th class="text-left pb-1.5 text-[10px] font-black text-green-600 uppercase">Produk</th>
                                    <th class="text-center pb-1.5 text-[10px] font-black text-green-600 uppercase">Qty</th>
                                    <th class="text-right pb-1.5 text-[10px] font-black text-green-600 uppercase">COGS</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($warranty->delivery->items as $di)
                                    <tr class="border-b border-green-100">
                                        <td class="py-1.5 text-gray-700">{{ $di->product->name ?? '-' }}</td>
                                        <td class="py-1.5 text-center font-bold">{{ $di->qty }}</td>
                                        <td class="py-1.5 text-right text-gray-600">
                                            Rp {{ number_format($di->cogs_total, 0, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

        </div>

        {{-- RIGHT SIDEBAR --}}
        <div class="col-span-4 space-y-4">

            {{-- Aksi --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <h3 class="font-bold text-gray-600 text-sm mb-4">Aksi</h3>

                <div class="space-y-2.5">
                    {{-- Terima & Posting Garansi (draft / data lama 'received' yang belum diposting) --}}
                    @if(in_array($warranty->status, ['draft', 'received']))
                        <form action="{{ route('sales.warranty.receive', $warranty->id) }}" method="POST">
                            @csrf
                            <button type="submit"
                                    onclick="return confirm('Konfirmasi terima barang & posting garansi?\nStok masuk 1131, jurnal Dr 1131 / Cr 1132 dicatat.')"
                                    class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl text-sm font-bold transition">
                                ✓ Terima & Posting Garansi
                            </button>
                        </form>
                    @endif

                    {{-- Selesai Diperbaiki: OTOMATIS saat OP Perbaikan difinalisasi (harus sudah diposting) --}}
                    @if($warranty->status === 'posted' && $warranty->type === 'repair')
                        @if($repairOrders->isNotEmpty())
                            {{-- Ada OP perbaikan → status garansi otomatis selesai saat OP difinalisasi --}}
                            <div class="p-3 bg-amber-50 border border-amber-100 rounded-xl text-xs text-amber-800">
                                <p class="font-bold mb-1">⏳ Menunggu perbaikan produksi</p>
                                <p class="text-amber-700 mb-2">Status garansi otomatis jadi "Selesai Diperbaiki" begitu OP perbaikan difinalisasi.</p>
                                @foreach($repairOrders as $po)
                                    <a href="{{ route('production.orders.show', $po->id) }}"
                                       class="flex items-center justify-between gap-2 text-blue-600 font-semibold hover:underline py-0.5">
                                        <span>{{ $po->order_number }}</span>
                                        <span class="text-[10px] uppercase font-bold text-gray-500">{{ $po->status_label }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            {{-- Belum ada OP perbaikan → fallback manual --}}
                            <form action="{{ route('sales.warranty.mark-repaired', $warranty->id) }}" method="POST"
                                  onsubmit="return confirm('Tandai perbaikan selesai secara manual?')">
                                @csrf
                                <div class="mb-2">
                                    <label class="block text-xs font-bold text-gray-500 mb-1">Biaya Perbaikan (Rp)</label>
                                    <input type="text" inputmode="numeric" name="repair_cost" placeholder="0"
                                           class="rupiah-input w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                                    <p class="text-[10px] text-gray-400 mt-1">Opsional — dicatat sebagai referensi biaya perbaikan.</p>
                                </div>
                                <button type="submit"
                                        class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-xl text-sm font-bold transition">
                                    🔧 Tandai Selesai (Manual)
                                </button>
                                <p class="text-[10px] text-gray-400 mt-1">Belum ada OP Perbaikan. Buat OP Perbaikan agar status update otomatis.</p>
                            </form>
                        @endif
                    @endif

                    {{-- Tampilkan repair cost jika sudah diisi --}}
                    @if(in_array($warranty->status, ['repaired', 'shipped']) && $warranty->type === 'repair' && $warranty->repair_cost > 0)
                        <div class="p-3 bg-indigo-50 border border-indigo-100 rounded-xl text-xs text-indigo-700">
                            <span class="font-bold">Biaya Perbaikan:</span>
                            Rp {{ number_format($warranty->repair_cost, 0, ',', '.') }}
                        </div>
                    @endif

                    {{-- Edit (draft only) --}}
                    @if($warranty->status === 'draft')
                        <a href="{{ route('sales.warranty.edit', $warranty->id) }}"
                           class="block w-full text-center border border-gray-200 text-gray-600 hover:bg-gray-50 py-2.5 rounded-xl text-sm font-semibold transition">
                            Edit Draft
                        </a>
                        <form action="{{ route('sales.warranty.destroy', $warranty->id) }}" method="POST"
                              onsubmit="return confirm('Hapus draft garansi ini?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="w-full border border-red-200 text-red-600 hover:bg-red-50 py-2.5 rounded-xl text-sm font-semibold transition">
                                Hapus Draft
                            </button>
                        </form>
                    @endif

                    {{-- Void (hanya bila tidak ada SJ posted) --}}
                    @if($warranty->canBeVoided())
                        <form action="{{ route('sales.warranty.void', $warranty->id) }}" method="POST"
                              onsubmit="return confirm('Void garansi ini? Stok masuk akan dibalik & jurnal dibatalkan.')">
                            @csrf
                            <button type="submit"
                                    class="w-full bg-gray-600 hover:bg-gray-700 text-white py-2.5 rounded-xl text-sm font-bold transition">
                                Void Garansi
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Jurnal Posting Garansi --}}
            @if($postJournal)
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-600 text-sm mb-1">Jurnal Posting Garansi</h3>
                    <div class="text-xs text-gray-400 mb-3">{{ $postJournal->journal_number }}</div>
                    <div class="space-y-1.5">
                        @foreach($postJournal->lines as $line)
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600 {{ $line->credit > 0 ? 'pl-4' : '' }}">
                                    {{ $line->debit > 0 ? 'Dr.' : 'Cr.' }}
                                    {{ $line->account->code }} {{ $line->account->name }}
                                </span>
                                <span class="font-bold {{ $line->debit > 0 ? 'text-red-600' : 'text-green-600' }}">
                                    Rp {{ number_format($line->debit > 0 ? $line->debit : $line->credit, 0, ',', '.') }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Jurnal SJ (hanya untuk referensi, tidak ada jurnal baru sejak redesign) --}}
            @if($journal)
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-600 text-sm mb-1">Jurnal SJ Garansi</h3>
                    <div class="text-xs text-gray-400 mb-3">{{ $journal->journal_number }}</div>
                    <div class="space-y-1.5">
                        @foreach($journal->lines as $line)
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600 {{ $line->credit > 0 ? 'pl-4' : '' }}">
                                    {{ $line->debit > 0 ? 'Dr.' : 'Cr.' }}
                                    {{ $line->account->code }} {{ $line->account->name }}
                                </span>
                                <span class="font-bold {{ $line->debit > 0 ? 'text-red-600' : 'text-green-600' }}">
                                    Rp {{ number_format($line->debit > 0 ? $line->debit : $line->credit, 0, ',', '.') }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Panduan Akuntansi --}}
            <div x-data="{ open: false }" class="bg-white rounded-2xl border border-gray-100 shadow-sm">
                <button @click="open = !open"
                        class="w-full flex items-center justify-between px-5 py-4 text-left">
                    <span class="flex items-center gap-2 text-sm font-bold text-gray-600">
                        <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Panduan & Akuntansi
                    </span>
                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="open ? 'rotate-180' : ''"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div x-show="open" class="border-t border-gray-50 px-5 pb-5 pt-4 space-y-4 text-xs text-gray-600">

                    <div>
                        <p class="font-black text-[10px] text-gray-400 uppercase tracking-widest mb-2">Alur — Perbaikan</p>
                        <ol class="space-y-2">
                            @foreach([
                                ['n'=>'1','c'=>'amber','t'=>'Draft','d'=>'Garansi dibuat, belum ada pergerakan.'],
                                ['n'=>'2','c'=>'blue','t'=>'Terima Barang','d'=>'Stok masuk ke 1131 Persediaan Perbaikan (last cost).'],
                                ['n'=>'3','c'=>'purple','t'=>'Posting Garansi','d'=>'Jurnal Dr.1131 Persediaan / Cr.1132 Beban Stok Garansi. WIP bisa dihitung.'],
                                ['n'=>'4','c'=>'indigo','t'=>'Produksi Selesai','d'=>'Jurnal Dr.1130 Persediaan / Cr.1140 WIP (normal).'],
                                ['n'=>'5','c'=>'green','t'=>'Post SJ','d'=>'Dr.1132 (HPP) + Dr.6106 (biaya perbaikan) / Cr.1130 Persediaan.'],
                            ] as $s)
                                <li class="flex gap-2 items-start">
                                    <span class="w-5 h-5 rounded-full bg-{{ $s['c'] }}-100 text-{{ $s['c'] }}-700 text-[9px] font-black flex items-center justify-center flex-shrink-0">{{ $s['n'] }}</span>
                                    <div><span class="font-bold text-gray-700">{{ $s['t'] }}</span> — <span class="text-gray-400">{{ $s['d'] }}</span></div>
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    <div class="bg-gray-50 rounded-xl p-3 border border-gray-100 space-y-2">
                        <div>
                            <p class="font-black text-[9px] text-gray-400 uppercase tracking-widest mb-1.5">Jurnal Saat Posting Garansi</p>
                            <div class="font-mono space-y-0.5">
                                <div>Dr. <span class="font-bold text-red-600">1131 Persediaan Perbaikan</span></div>
                                <div class="pl-4">Cr. <span class="font-bold text-green-700">1132 Beban Stok Garansi</span></div>
                            </div>
                            <p class="text-gray-400 mt-1">Nilai 1131 terisi → WIP bisa dihitung saat produksi.</p>
                        </div>
                        <div class="border-t border-gray-200 pt-2">
                            <p class="font-black text-[9px] text-gray-400 uppercase tracking-widest mb-1.5">Jurnal Saat Produksi Selesai</p>
                            <div class="font-mono space-y-0.5">
                                <div>Dr. <span class="font-bold text-red-600">1130 Persediaan</span></div>
                                <div class="pl-4">Cr. <span class="font-bold text-green-700">1140 WIP</span></div>
                            </div>
                        </div>
                        <div class="border-t border-gray-200 pt-2">
                            <p class="font-black text-[9px] text-gray-400 uppercase tracking-widest mb-1.5">Jurnal Saat Post SJ</p>
                            <div class="font-mono space-y-0.5">
                                <div>Dr. <span class="font-bold text-red-600">1132 Beban Stok Garansi</span> <span class="text-gray-400">(HPP customer)</span></div>
                                <div>Dr. <span class="font-bold text-red-600">6106 Beban Garansi</span> <span class="text-gray-400">(biaya perbaikan)</span></div>
                                <div class="pl-4">Cr. <span class="font-bold text-green-700">1130 Persediaan</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-amber-50 border border-amber-100 rounded-xl p-3">
                        <p class="font-bold text-amber-700 mb-1">Untuk Audit</p>
                        <ul class="text-amber-700 space-y-1 leading-relaxed">
                            <li>• <strong>1132</strong> berpasangan: Cr saat posting garansi, Dr saat SJ → netting nol.</li>
                            <li>• <strong>6106 Beban Garansi</strong> = biaya perbaikan aktual (bukan HPP barang customer).</li>
                            <li>• WIP produksi perbaikan dihitung dari nilai 1131 yang terisi saat posting.</li>
                        </ul>
                    </div>

                </div>
            </div>

            {{-- Info Gudang --}}
            <div class="bg-gray-50 rounded-2xl border border-gray-100 p-4 text-xs text-gray-500 space-y-1">
                <div><span class="font-bold text-gray-600">Gudang:</span> {{ $warranty->warehouse->name ?? '-' }}</div>
                <div><span class="font-bold text-gray-600">Dibuat:</span> {{ $warranty->created_at->format('d/m/Y H:i') }}</div>
                @if($warranty->notes)
                    <div class="pt-1 border-t border-gray-200"><span class="font-bold text-gray-600">Catatan:</span> {{ $warranty->notes }}</div>
                @endif
            </div>

        </div>

    </div>

    @include('erp.sales._partials.relations-warranty')

</div>
@endsection

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function sjForm() {
    return {
        items: [{ product_id: null, productQuery: '', qty: 1, results: [], showDrop: false }],

        async searchProduct(idx) {
            const q = this.items[idx].productQuery;
            if (q.length < 2) { this.items[idx].results = []; return; }
            const res = await fetch(`/erp/api/products/search?q=${encodeURIComponent(q)}`);
            this.items[idx].results = await res.json();
            this.items[idx].showDrop = true;
        },

        selectProduct(idx, p) {
            this.items[idx].product_id   = p.id;
            this.items[idx].productQuery = `${p.sku} - ${p.name}`;
            this.items[idx].results      = [];
            this.items[idx].showDrop     = false;
        },
    }
}
</script>
@endpush
