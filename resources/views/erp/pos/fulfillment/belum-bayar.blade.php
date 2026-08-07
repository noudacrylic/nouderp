@extends('layouts.erp')

@section('content')
<div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="text-lg font-semibold">Belum Bayar</h1>
        <p class="text-xs text-gray-500">
            Pesanan yang belum menerima pembayaran sama sekali — termasuk yang masih draft (belum diposting).
            Otomatis pindah begitu pembayaran/DP masuk.
        </p>
    </div>
</div>

@include('erp.pos.fulfillment._filters', ['couriers' => $couriers])

<div class="space-y-5">
    @forelse($rows as $row)
        @include('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => 'belum_bayar'])
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">
            Tidak ada pesanan yang menunggu pembayaran.
        </div>
    @endforelse
</div>

@include('erp.sales.payment._midtrans_modals')
@include('erp.pos.fulfillment._seller_notes_js')
@include('erp.pos.fulfillment._copy_js')
@include('erp.pos.fulfillment._fokus_js')
@endsection
