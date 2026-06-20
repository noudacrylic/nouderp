{{-- Bagian kiri kartu pesanan: badge jenis, nomor, customer, tanggal. Param: $row --}}
@php
    $isSo = $row['kind'] === 'so';
    $dateStr = $row['date'] ? \Carbon\Carbon::parse($row['date'])->format('d M Y') : '-';
@endphp
<div class="min-w-0">
    <div class="flex items-center gap-2 flex-wrap">
        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $isSo ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' }}">
            {{ $isSo ? 'SO' : 'Garansi' }}
        </span>
        @php $copyNumber = marketplace_copy_number($row['number'], !empty($row['is_marketplace'])); @endphp
        <span class="js-copy text-sm font-bold text-gray-800 cursor-pointer hover:text-indigo-600" data-copy="{{ $copyNumber }}" title="Klik untuk salin nomor">{{ $row['number'] }}</span>
        @if($isSo && $row['is_pickup'])
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700">🏬 AMBIL DI TOKO</span>
        @endif
    </div>
    <div class="text-sm text-gray-600 mt-0.5">{{ $row['customer'] }}</div>
    <div class="text-[11px] text-gray-400">{{ $dateStr }}</div>
</div>
