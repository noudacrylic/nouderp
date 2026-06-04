@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">Telah Diproses</h1>
        <p class="text-xs text-gray-500">Pesanan yang sudah punya invoice / sudah dikirim. Generate resi, cetak Surat Jalan & invoice di sini.</p>
    </div>
</div>

@if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>@endif
@if(session('success'))<div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>@endif

<form method="GET" class="mb-3">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari nomor / customer…"
           class="border rounded px-3 py-2 text-sm w-72">
</form>

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
                           class="px-2.5 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">Print SJ</a>
                    </div>
                @endif
            </div>
        @else
            @include('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => 'telah_diproses'])
        @endif
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">Belum ada pesanan yang diproses.</div>
    @endforelse
</div>

@include('erp.pos.fulfillment._seller_notes_js')
@include('erp.pos.fulfillment._copy_js')
@endsection
