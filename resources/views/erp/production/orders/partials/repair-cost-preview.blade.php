{{-- Pratinjau HPP OP Perbaikan: HPP awal tiap produk tetap miliknya, biaya perbaikan dibagi
     sebanding HPP awal. Butuh $order (outputs.product) & $repairCost (repairCostBreakdown). --}}
@php
    $totalAwal = (float) $repairCost['total_awal'];
    $tambahan  = (float) $repairCost['tambahan'];
    $naik      = $totalAwal > 0 ? $tambahan / $totalAwal * 100 : null;
    $angka     = fn ($v) => number_format((float) $v, 0, ',', '.');
@endphp
<div class="mb-4 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-800">
    <div class="flex items-start gap-2 mb-2">
        <span class="font-black">ℹ️</span>
        <span>
            <b>HPP awal</b> tiap produk tetap miliknya sendiri. <b>Biaya perbaikan</b> (Penambahan Bahan + Biaya Produksi)
            dibagi <b>sebanding HPP awal</b>@if($naik !== null) — semua produk naik {{ number_format($naik, 1, ',', '.') }}%@endif.
            Unit yang <b>gagal diperbaiki</b> tidak masuk stok; nilainya dibebankan ke <b>Beban Kerugian Retur</b>.
        </span>
    </div>
    <table class="w-full bg-white rounded border border-amber-100">
        <thead class="text-[10px] font-black text-gray-400 uppercase">
            <tr>
                <th class="px-2 py-1 text-left">Produk</th>
                <th class="px-2 py-1 text-right">Unit</th>
                <th class="px-2 py-1 text-right">HPP awal</th>
                <th class="px-2 py-1 text-right">+ Biaya perbaikan</th>
                <th class="px-2 py-1 text-right">HPP / unit</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-amber-50 text-gray-700">
            @foreach($order->outputs as $o)
                @php
                    $awal = (float) ($repairCost['awal'][$o->id] ?? 0);
                    $full = (float) ($repairCost['full'][$o->id] ?? 0);
                    $unit = (float) $o->qty_planned > 0 ? $full / (float) $o->qty_planned : 0;
                @endphp
                <tr>
                    <td class="px-2 py-1">{{ $o->product?->name ?? '—' }}</td>
                    <td class="px-2 py-1 text-right">{{ rtrim(rtrim(number_format((float) $o->qty_planned, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="px-2 py-1 text-right font-mono">{{ $angka($awal) }}</td>
                    <td class="px-2 py-1 text-right font-mono">{{ $angka($full - $awal) }}</td>
                    <td class="px-2 py-1 text-right font-mono font-bold">{{ $angka($unit) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="text-gray-800 font-bold border-t border-amber-100">
            <tr>
                <td class="px-2 py-1" colspan="2">Total</td>
                <td class="px-2 py-1 text-right font-mono">{{ $angka($totalAwal) }}</td>
                <td class="px-2 py-1 text-right font-mono">{{ $angka($tambahan) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
