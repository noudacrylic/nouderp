{{-- Penanda jumlah foto nota di baris daftar. Butuh withCount('fotoNota'). --}}
@if (($n = (int) ($doc->foto_nota_count ?? 0)) > 0)
    <span class="ml-1 text-[11px] text-emerald-700" title="{{ $n }} foto nota">📎{{ $n > 1 ? $n : '' }}</span>
@endif
