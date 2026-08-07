@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">Dikirim</h1>
        <p class="text-xs text-gray-500">Paket sudah diserahkan ke jasa kirim dan masih di jalan. Non-marketplace: cek posisi paket lewat "Lacak", lalu tekan "Sudah Sampai" pada Surat Jalannya — pesanan pindah ke "Selesai" begitu semua paketnya sampai. Marketplace mengikuti status Jubelio.</p>
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
@include('erp.pos.fulfillment._fokus_js')
@endsection
