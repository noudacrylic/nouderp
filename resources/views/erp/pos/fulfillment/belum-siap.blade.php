@extends('layouts.erp')

@section('content')
<div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="text-lg font-semibold">Belum Siap</h1>
        <p class="text-xs text-gray-500">Pesanan belum bisa diproses (menunggu pembayaran / produksi / perbaikan), termasuk pesanan marketplace yang belum dibayar. Otomatis pindah ke "Perlu Diproses" saat siap.</p>
    </div>
    {{-- Tarik manual pesanan marketplace (cadangan webhook). --}}
    <form method="POST" action="{{ route('pos.fulfillment.sync-marketplace') }}">
        @csrf
        <button type="submit" class="text-xs px-3 py-2 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">
            🔄 Tarik Pesanan Baru
        </button>
    </form>
</div>


@include('erp.pos.fulfillment._filters', ['couriers' => $couriers])

<div class="space-y-5">
    @forelse($rows as $row)
        @if($row['kind'] === 'garansi')
            <div class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-rose-400 shadow-md hover:shadow-lg transition-shadow p-4 flex items-center justify-between gap-3">
                @include('erp.pos.fulfillment._card_top', ['row' => $row])
                <div class="text-right shrink-0">
                    <div class="text-xs text-gray-500">{{ $row['status_label'] }}</div>
                    <span class="inline-block mt-1 px-2 py-0.5 rounded text-[11px] bg-gray-100 text-gray-500">⏳ {{ $row['reason'] }}</span>
                </div>
            </div>
        @elseif($row['kind'] === 'mp_pending')
            {{-- Pesanan marketplace belum jadi SO (mis. belum dibayar) — kartu info read-only. --}}
            <div class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-amber-400 shadow-md p-4">
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
            @include('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => 'belum_siap'])
        @endif
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">Tidak ada pesanan di status ini.</div>
    @endforelse
</div>

@include('erp.sales.payment._midtrans_modals')
@include('erp.pos.fulfillment._seller_notes_js')
@include('erp.pos.fulfillment._copy_js')
@endsection
