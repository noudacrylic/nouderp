{{--
    Satu baris status PPh Final 0,5% faktur ini — dari Laporan Pajak (PphFinalReportService).
    Setor sendiri: nilai pajak + sudah/belum disetor di masanya. Marketplace: dipotong / akan
    dipotong marketplace. Tidak ikut dicetak.
--}}
@php
    $pph = app(\App\Modules\Finance\Services\PphFinalReportService::class)->statusFaktur($invoice->id);
@endphp
@if($pph)
@php
    $pphRp = 'Rp ' . number_format($pph['pajak'], 0, ',', '.');
    $pphCls = [
        'lunas' => 'bg-green-100 text-green-700', 'sebagian' => 'bg-amber-100 text-amber-800', 'belum' => 'bg-red-100 text-red-700',
        'bebas' => 'bg-gray-100 text-gray-600', 'nihil' => 'bg-gray-100 text-gray-500',
        'dipotong_mp' => 'bg-emerald-100 text-emerald-700', 'menunggu_mp' => 'bg-sky-100 text-sky-700',
    ][$pph['bayar']] ?? 'bg-gray-100 text-gray-600';
    $pphUrl = route('accounting.reports.pajak.masa', [$pph['masa']->year, $pph['masa']->month])
        . '?q=' . urlencode($invoice->invoice_number) . '#faktur';
@endphp
<div class="mt-4 print:hidden">
    <div class="bg-white border border-gray-100 rounded-lg shadow-sm px-4 py-2.5 text-sm flex flex-wrap items-center gap-x-3 gap-y-1">
        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">Pajak</span>
        @if($pph['status'] === 'sendiri')
            <span class="text-gray-700">PPh Final 0,5% (setor sendiri): <b class="tabular-nums">{{ $pphRp }}</b></span>
        @elseif($pph['status'] === 'dipungut')
            <span class="text-gray-700">PPh 22 dipotong marketplace: <b class="tabular-nums">{{ $pphRp }}</b>
                @if($pph['sumber_pajak'] === 'taksiran')<span class="text-[11px] text-amber-700">(taksiran)</span>@endif
            </span>
        @else
            <span class="text-gray-700">PPh 22 akan dipotong marketplace saat pesanan selesai: <b class="tabular-nums">≈ {{ $pphRp }}</b></span>
        @endif
        <span class="px-1.5 py-0.5 rounded text-xs {{ $pphCls }}">{{ $pph['bayar_label'] }}</span>
        <span class="text-xs text-gray-500">masa {{ $pph['masa']->translatedFormat('F Y') }}</span>
        <a href="{{ $pphUrl }}" class="ml-auto text-xs text-blue-600 hover:underline">Laporan Pajak →</a>
    </div>
</div>
@endif
