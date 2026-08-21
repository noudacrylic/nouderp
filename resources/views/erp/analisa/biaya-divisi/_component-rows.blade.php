{{-- Baris komponen sebuah grup: tampilan, baris ubah (tersembunyi), lalu baris tambah.
     Butuh: $components, $formId (form tambah), $indent (padding-left kolom pertama), $accent --}}
@php
    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $badge = [
        'gaji'   => ['otomatis', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'akun'   => ['akun',     'bg-blue-50 text-blue-700 border-blue-200'],
        'manual' => ['manual',   'bg-slate-100 text-slate-600 border-slate-200'],
    ];
@endphp

@foreach($components as $line)
    @php $b = $badge[$line['source']] ?? $badge['manual']; @endphp

    <tr class="cmp-view border-t border-slate-50 hover:bg-slate-50/70 transition">
        <td class="py-2.5 pr-3 relative" style="padding-left:{{ $indent }}px">
            <span class="absolute w-px bg-slate-200 top-0 bottom-0" style="left:{{ $indent - 22 }}px"></span>
            <span class="absolute h-px bg-slate-200 w-3.5 top-1/2" style="left:{{ $indent - 22 }}px"></span>
            <span class="text-slate-700">{{ $line['name'] }}</span>
            <span class="ml-1.5 text-[11px] font-semibold border rounded-md px-1.5 py-0.5 {{ $b[1] }}">{{ $b[0] }}</span>
        </td>

        <td class="py-2.5 px-3 text-xs {{ $line['amount'] <= 0 && $line['source'] === 'manual' ? 'text-amber-600' : 'text-slate-500' }}">
            {{ $line['amount'] <= 0 && $line['source'] === 'manual' ? 'belum diisi — klik Ubah untuk isi nominalnya' : $line['detail'] }}
        </td>

        <td class="py-2.5 px-3 text-right tabular-nums {{ $line['amount'] > 0 ? 'text-slate-800 font-medium' : 'text-slate-300' }}">
            {{ $rp($line['amount']) }}
        </td>

        <td class="py-2.5 pl-3 pr-5 text-right whitespace-nowrap">
            @if($line['locked'])
                <span class="text-[11px] text-slate-400" title="Baris ini selalu ada selama masih ada karyawan yang digaji">
                    dari slip gaji
                </span>
            @else
                <button type="button" class="btn-edit-row text-slate-500 hover:text-blue-700 text-xs font-semibold">Ubah</button>
                <button type="submit" form="delcmp-{{ $line['id'] }}"
                        onclick="return confirm('Hapus komponen {{ $line['name'] }}? Biayanya tidak lagi masuk HPP.')"
                        class="ml-2 text-slate-400 hover:text-red-600 text-xs font-semibold">Hapus</button>
            @endif
        </td>
    </tr>

    @unless($line['locked'])
        {{-- Baris ubah — kembar dengan baris tambah, hanya prefilled + kirim ke route update. --}}
        <tr class="cmp-edit hidden bg-{{ $accent }}-50/40">
            <td class="py-2.5 pr-3" style="padding-left:{{ $indent }}px">
                <input type="text" name="name" value="{{ $line['name'] }}" form="editcmp-{{ $line['id'] }}"
                       class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm w-full">
            </td>
            <td class="py-2.5 px-3">
                @include('erp.analisa.biaya-divisi._source-fields', [
                    'formId'     => 'editcmp-' . $line['id'],
                    'source'     => $line['source'],
                    'accountId'  => $line['account_id'],
                    'percentage' => $line['percentage'],
                    'notes'      => $line['notes'] ?? null,
                ])
            </td>
            <td class="py-2.5 px-3 text-right">
                <input type="text" name="amount_monthly" form="editcmp-{{ $line['id'] }}" placeholder="0"
                       value="{{ $line['amount_monthly'] > 0 ? number_format($line['amount_monthly'], 0, ',', '.') : '' }}"
                       class="komponen-manual rupiah-input border border-slate-200 rounded-lg px-3 py-1.5 text-sm w-32 text-right {{ $line['source'] === 'akun' ? 'hidden' : '' }}">
                <span class="komponen-akun text-xs text-slate-400 {{ $line['source'] === 'akun' ? '' : 'hidden' }}">dari buku besar</span>
            </td>
            <td class="py-2.5 pl-3 pr-5 text-right whitespace-nowrap">
                <button type="submit" form="editcmp-{{ $line['id'] }}"
                        class="bg-{{ $accent }}-600 hover:bg-{{ $accent }}-700 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition">Simpan</button>
                <button type="button" class="btn-cancel-edit text-slate-400 hover:text-slate-600 text-xs font-semibold ml-1">Batal</button>
            </td>
        </tr>
    @endunless
@endforeach

{{-- Baris tambah, muncul saat "Tambah baris" diklik --}}
<tr class="add-row hidden bg-{{ $accent }}-50/40">
    <td class="py-2.5 pr-3" style="padding-left:{{ $indent }}px">
        <input type="text" name="name" form="{{ $formId }}" placeholder="Nama biaya…"
               class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm w-full">
    </td>
    <td class="py-2.5 px-3">
        @include('erp.analisa.biaya-divisi._source-fields', [
            'formId'     => $formId,
            'source'     => 'manual',
            'accountId'  => null,
            'percentage' => 100,
            'notes'      => null,
        ])
    </td>
    <td class="py-2.5 px-3 text-right">
        <input type="text" name="amount_monthly" form="{{ $formId }}" placeholder="0"
               class="komponen-manual rupiah-input border border-slate-200 rounded-lg px-3 py-1.5 text-sm w-32 text-right">
        <span class="komponen-akun hidden text-xs text-slate-400">dari buku besar</span>
    </td>
    <td class="py-2.5 pl-3 pr-5 text-right whitespace-nowrap">
        <button type="submit" form="{{ $formId }}"
                class="bg-{{ $accent }}-600 hover:bg-{{ $accent }}-700 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition">Simpan</button>
        <button type="button" class="btn-cancel-row text-slate-400 hover:text-slate-600 text-xs font-semibold ml-1">Batal</button>
    </td>
</tr>

{{-- Pemicu --}}
<tr class="add-trigger">
    <td colspan="4" class="py-1.5 pr-5" style="padding-left:{{ $indent }}px">
        <button type="button" class="btn-add-row text-xs font-semibold text-{{ $accent }}-600 hover:text-{{ $accent }}-800 transition">
            + Tambah baris
        </button>
    </td>
</tr>
