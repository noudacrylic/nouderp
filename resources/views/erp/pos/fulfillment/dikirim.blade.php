@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">Dikirim</h1>
        <p class="text-xs text-gray-500">Pesanan marketplace yang sudah diserahkan ke jasa kirim (status Jubelio: dikirim). Masih bisa cetak ulang resi/faktur. Pindah ke "Selesai" saat transaksi marketplace selesai.</p>
    </div>
</div>

@include('erp.pos.fulfillment._filters', ['couriers' => $couriers])

<div class="space-y-5">
    @forelse($rows as $row)
        @include('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => 'dikirim'])
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">Belum ada pesanan yang dikirim.</div>
    @endforelse
</div>

@include('erp.pos.fulfillment._seller_notes_js')
@include('erp.pos.fulfillment._copy_js')
@endsection
