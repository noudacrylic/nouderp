{{-- Satu baris akun di Laporan Laba Rugi. Var: $a (punya code,name,balance,id) --}}
<tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('accounts.show', $a->id) }}">
    <td class="px-3 py-1.5 text-gray-500 w-16 pl-8">{{ $a->code }}</td>
    <td class="px-3 py-1.5">{{ $a->name }}</td>
    <td class="px-3 py-1.5 text-right tabular-nums {{ $a->balance < 0 ? 'text-red-600' : '' }}">
        @if($a->balance < 0)
            ({{ number_format(abs($a->balance), 0, ',', '.') }})
        @else
            {{ number_format($a->balance, 0, ',', '.') }}
        @endif
    </td>
</tr>
