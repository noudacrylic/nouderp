{{-- Satu baris tugas. Param: $t, $jenis (label). --}}
@php use App\Modules\Tasks\TugasOwner\Models\TugasOwner as Tugas; @endphp
<a href="{{ route('tasks.meja.tugas.show', $t->id) }}" class="block bg-white border {{ $t->lewatTenggat() ? 'border-red-300' : 'border-gray-200' }} rounded-xl px-4 py-3 hover:border-blue-400">
    <div class="flex items-center justify-between gap-3">
        <span class="font-semibold text-sm text-gray-800 truncate">{{ $t->judul }}</span>
        <span class="shrink-0 px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700">{{ Tugas::LABEL_TAHAP[$t->tahap] ?? $t->tahap }}</span>
    </div>
    <div class="flex flex-wrap gap-x-3 text-xs text-gray-500 mt-0.5">
        <span>{{ $jenis }}</span>
        <span>Untuk {{ $t->pengerja?->name }}</span>
        @if ($t->tenggat)<span class="{{ $t->lewatTenggat() ? 'text-red-600 font-bold' : '' }}">Tenggat {{ $t->tenggat->format('d/m/Y') }}</span>@endif
        @if ($t->jumlah_revisi)<span>{{ $t->jumlah_revisi }}× revisi</span>@endif
    </div>
</a>
