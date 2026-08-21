{{-- Tabel sampel OP. $samples, $departments, $selectable wajib diisi pemanggil. --}}
@php
    $flagLabels = [
        'no_cycles'        => ['Siklus 0', 'bg-red-50 text-red-700 border-red-200'],
        'zero_time'        => ['Total waktu 0', 'bg-red-50 text-red-700 border-red-200'],
        'multi_main'       => ['Lebih dari satu output utama', 'bg-red-50 text-red-700 border-red-200'],
        'merged_parent'    => ['Induk gabungan', 'bg-red-50 text-red-700 border-red-200'],
        'merged_child'     => ['Anak gabungan', 'bg-red-50 text-red-700 border-red-200'],
        'unfinished_steps' => ['Ada langkah belum selesai', 'bg-amber-50 text-amber-700 border-amber-200'],
        'zero_division'    => ['Ada divisi 0 detik', 'bg-amber-50 text-amber-700 border-amber-200'],
        'no_bom'           => ['Tanpa BOM', 'bg-gray-100 text-gray-500 border-gray-200'],
    ];
@endphp

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-100">
                @if($selectable)
                    <th class="px-4 py-3 text-center font-black text-gray-400 text-[10px] uppercase tracking-widest">Pakai</th>
                @endif
                <th class="px-4 py-3 text-left font-black text-gray-400 text-[10px] uppercase tracking-widest">No OP</th>
                <th class="px-3 py-3 text-left font-black text-gray-400 text-[10px] uppercase tracking-widest">Tanggal</th>
                <th class="px-3 py-3 text-left font-black text-gray-400 text-[10px] uppercase tracking-widest">Tipe</th>
                <th class="px-3 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Siklus</th>
                @foreach($departments as $dept)
                    <th class="px-3 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest whitespace-nowrap">{{ $dept['name'] }}</th>
                @endforeach
                <th class="px-3 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Total</th>
                <th class="px-4 py-3 text-right font-black text-gray-500 text-[10px] uppercase tracking-widest bg-gray-100 whitespace-nowrap">Total/Siklus</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @forelse($samples as $s)
                <tr class="{{ $s['excluded'] ? 'bg-gray-50/70 text-gray-400' : 'hover:bg-gray-50' }}">
                    @if($selectable)
                        <td class="px-4 py-3 text-center">
                            <input type="hidden" name="rendered[]" value="{{ $s['order_id'] }}">
                            <input type="checkbox" name="use[]" value="{{ $s['order_id'] }}"
                                   class="sample-check" @checked(!$s['excluded'])>
                        </td>
                    @endif
                    <td class="px-4 py-3">
                        <a href="{{ route('production.orders.show', $s['order_id']) }}"
                           class="font-mono text-sm font-bold text-blue-600 hover:underline">{{ $s['order_number'] }}</a>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @if($s['is_outlier'])
                                <span class="text-xs font-semibold border rounded px-1.5 py-0.5 bg-red-50 text-red-700 border-red-200">menyimpang?</span>
                            @endif
                            @foreach($s['flags'] as $flag)
                                @if(isset($flagLabels[$flag]))
                                    <span class="text-xs font-semibold border rounded px-1.5 py-0.5 {{ $flagLabels[$flag][1] }}">{{ $flagLabels[$flag][0] }}</span>
                                @endif
                            @endforeach
                        </div>
                        @if($s['excluded'] && $s['exclusion_reason'])
                            <div class="text-xs text-red-600 mt-0.5">Alasan: {{ $s['exclusion_reason'] }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-3 text-gray-600 text-xs whitespace-nowrap">{{ $s['production_date'] }}</td>
                    <td class="px-3 py-3 text-gray-600 text-xs whitespace-nowrap">
                        {{ $s['type_label'] }}
                        <span class="block text-xs font-semibold text-blue-600">{{ $s['status_label'] }}</span>
                    </td>
                    <td class="px-3 py-3 text-right text-gray-700">{{ rtrim(rtrim(number_format($s['planned_cycles'], 2, ',', '.'), '0'), ',') }}</td>
                    @foreach($departments as $dept)
                        @php $sec = $s['sec'][$dept['id']] ?? null; @endphp
                        <td class="px-3 py-3 text-right whitespace-nowrap {{ $sec === 0 ? 'text-amber-600' : 'text-gray-700' }}">
                            {{ $sec === null ? '—' : dur_hms((float) $sec) }}
                        </td>
                    @endforeach
                    <td class="px-3 py-3 text-right text-gray-700 whitespace-nowrap">{{ dur_hms($s['total_sec']) }}</td>
                    <td class="px-4 py-3 text-right font-bold bg-gray-50 whitespace-nowrap {{ $s['excluded'] ? 'text-gray-400' : 'text-gray-800' }}">
                        {{ dur_hms($s['total_sec_per_cycle']) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ ($selectable ? 7 : 6) + count($departments) }}" class="px-5 py-8 text-center text-gray-400 text-xs">
                        Tidak ada data.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
