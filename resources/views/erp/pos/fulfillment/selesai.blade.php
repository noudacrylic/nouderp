@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">Selesai</h1>
        <p class="text-xs text-gray-500">Pesanan tuntas — marketplace: faktur terbit / transaksi selesai · non-marketplace: sudah ber-resi atau tak perlu resi (ambil di toko / kurir manual). Otomatis terarsip &gt; 3 hari.</p>
    </div>
</div>

@include('erp.pos.fulfillment._filters', ['couriers' => $couriers])

<div class="space-y-5">
    @forelse($rows as $row)
        @if($row['kind'] === 'garansi')
            @php $gd = $row['delivery'] && $row['delivery']->status === 'posted' ? $row['delivery'] : null; @endphp
            <div class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-rose-400 shadow-md hover:shadow-lg transition-shadow p-4">
                <div class="flex items-start justify-between gap-3">
                    @include('erp.pos.fulfillment._card_top', ['row' => $row])
                    <span class="text-xs text-gray-500 shrink-0">{{ $row['status_label'] }}</span>
                </div>
                @if($gd)
                    <div class="mt-3 border-t border-gray-50 pt-3 flex items-center justify-between gap-2 flex-wrap text-xs bg-gray-50/70 rounded-lg px-3 py-1.5">
                        <span class="font-semibold text-gray-700">📄 <span class="js-copy cursor-pointer hover:text-indigo-600" data-copy="{{ $gd->delivery_number }}" title="Klik untuk salin nomor SJ">{{ $gd->delivery_number }}</span></span>
                        <a href="{{ route('sales.deliveries.print', $gd->id) }}"
                           class="px-2.5 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">Cetak SJ</a>
                    </div>
                @endif
            </div>
        @else
            @include('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => 'selesai'])
        @endif
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">Belum ada pesanan yang selesai.</div>
    @endforelse
</div>

@include('erp.pos.fulfillment._seller_notes_js')
@include('erp.pos.fulfillment._copy_js')
@endsection
