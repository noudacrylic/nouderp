@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">Belum Siap</h1>
        <p class="text-xs text-gray-500">Pesanan belum bisa diproses (menunggu pembayaran / produksi / perbaikan). Otomatis pindah ke "Perlu Diproses" saat siap.</p>
    </div>
</div>


<form method="GET" class="mb-3">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari nomor / pelanggan…"
           class="border rounded px-3 py-2 text-sm w-72">
</form>

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
