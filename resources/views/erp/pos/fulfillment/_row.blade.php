{{-- Satu baris daftar Pemrosesan Pesanan. Tiga jenis baris bisa muncul di bucket mana pun,
     jadi percabangannya dikumpulkan di sini alih-alih disalin ke tiap halaman tab.

     Param:
       $row                 baris dari FulfillmentReadinessService
       $mode                mode kartu SO ('belum_bayar' | 'belum_siap' | 'perlu_diproses' | …)
       $warrantyActionable  garansi boleh dikerjakan sekarang (tampilkan tombol prosesnya) --}}
@php $warrantyActionable = $warrantyActionable ?? false; @endphp
@if($row['kind'] === 'garansi')
    <div data-so-number="{{ $row['number'] }}" class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-rose-400 shadow-md hover:shadow-lg transition-shadow p-4">
        <div class="flex items-start justify-between gap-3">
            @include('erp.pos.fulfillment._card_top', ['row' => $row])
            @if($warrantyActionable)
                <a href="{{ route('sales.warranty.show', $row['id']) }}"
                   class="shrink-0 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-bold">Proses Garansi →</a>
            @else
                <span class="shrink-0 px-2 py-0.5 rounded text-[11px] bg-gray-100 text-gray-500">⏳ {{ $row['reason'] }}</span>
            @endif
        </div>
    </div>
@elseif($row['kind'] === 'mp_pending')
    {{-- Pesanan marketplace yang belum jadi SO (belum dibayar) — kartu info read-only. --}}
    <div data-so-number="{{ $row['number'] }}" class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-amber-400 shadow-md p-4">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700">🛒 {{ $row['channel'] }}</span>
            @php $copyNumber = marketplace_copy_number($row['number'], true); @endphp
            <span class="js-copy text-sm font-bold text-gray-800 cursor-pointer hover:text-indigo-600" data-copy="{{ $copyNumber }}" title="Klik untuk salin nomor (tanpa prefix channel)">{{ $row['number'] }}</span>
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700">⏳ Belum jadi SO</span>
            <span class="ml-auto text-xs text-gray-500 whitespace-nowrap">
                {{ $row['date'] ? \Carbon\Carbon::parse($row['date'])->format('d M Y') : '' }}
            </span>
        </div>
        <div class="mt-2 flex items-center gap-x-4 gap-y-1 flex-wrap text-[13px] text-gray-600">
            <span class="font-bold text-gray-800">{{ $row['customer'] }}</span>
            @if($row['item_count'])<span class="text-gray-400">{{ $row['item_count'] }} item</span>@endif
            <span>Total <b class="text-gray-800">Rp {{ number_format($row['grand_total'], 0, ',', '.') }}</b></span>
        </div>
        <div class="mt-1.5">
            <span class="inline-block px-2 py-0.5 rounded text-[11px] bg-gray-100 text-gray-500">⏳ {{ $row['reason'] }}</span>
        </div>
    </div>
@else
    @include('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => $mode])
@endif
