@extends('layouts.erp')

@section('content')
@php
    use App\Modules\Tasks\TugasOwner\Models\TugasOwner as Tugas;
    $jenisLabel = config('tugas_owner.jenis');
    $baris = function (Tugas $t) use ($jenisLabel) {
        return view('erp.tasks.tugas-owner._baris', ['t' => $t, 'jenis' => $jenisLabel[$t->jenis] ?? $t->jenis])->render();
    };
@endphp
<div class="max-w-4xl mx-auto">
    <div class="flex items-start justify-between gap-3 flex-wrap mb-4">
        <div>
            <h2 class="text-xl font-bold text-gray-800">Tugas dari Owner</h2>
            <p class="text-sm text-gray-500">Brief → admin menulis yang ia tangkap → owner setujui → dikerjakan → owner periksa.</p>
        </div>
        <a href="{{ route('tasks.meja.index') }}" class="border text-gray-600 hover:bg-gray-50 px-3 py-2 rounded text-sm">← Meja Kerja</a>
    </div>

    @if ($owner)
        <details class="bg-white border border-gray-200 rounded-xl p-4 mb-4" @if ($giliranSaya->isEmpty() && $menungguLain->isEmpty()) open @endif>
            <summary class="cursor-pointer font-semibold text-blue-700">＋ Buat tugas baru</summary>
            @if ($calon->isEmpty())
                <p class="text-sm text-amber-700 mt-3">Belum ada admin di meja Layanan atau Desain. Isi anggotanya di Manajer Admin › Peran &amp; Pengaturan.</p>
            @else
                <form method="POST" action="{{ route('tasks.meja.tugas.store') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">@csrf
                    <label class="sm:col-span-2 text-xs text-gray-500">Judul
                        <input type="text" name="judul" required maxlength="200" value="{{ old('judul') }}" placeholder="Mis. Upload Kotak Saran 2 Kotak ke Tokopedia" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm text-gray-800">
                    </label>
                    <label class="text-xs text-gray-500">Jenis
                        <select name="jenis" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm text-gray-800 bg-white">
                            @foreach ($jenisLabel as $k => $l)<option value="{{ $k }}" @selected(old('jenis') === $k)>{{ $l }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-xs text-gray-500">Untuk
                        <select name="pengerja_id" required class="mt-1 w-full border rounded-lg px-3 py-2 text-sm text-gray-800 bg-white">
                            @foreach ($calon as $u)<option value="{{ $u->id }}" @selected((int) old('pengerja_id') === $u->id)>{{ $u->name }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-xs text-gray-500">Tenggat (opsional)
                        <input type="date" name="tenggat" value="{{ old('tenggat') }}" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm text-gray-800">
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 self-end pb-2">
                        <input type="checkbox" name="tanpa_periksa" value="1" @checked(old('tanpa_periksa'))> Tugas kecil: tidak perlu saya periksa
                    </label>
                    <div class="sm:col-span-2">
                        <button class="px-4 py-2 rounded-lg text-sm font-semibold bg-blue-600 hover:bg-blue-700 text-white">Lanjut rekam brief →</button>
                        <span class="text-xs text-gray-500 ml-2">Admin baru melihatnya setelah brief dikirim.</span>
                    </div>
                </form>
            @endif
        </details>
    @endif

    <h3 class="font-semibold text-gray-800 mb-2">Menunggu Anda ({{ $giliranSaya->count() }})</h3>
    <div class="space-y-2 mb-5">
        @forelse ($giliranSaya as $t) {!! $baris($t) !!} @empty
            <p class="text-sm text-gray-500 px-1">Tidak ada.</p>
        @endforelse
    </div>

    @if ($menungguLain->isNotEmpty())
        <h3 class="font-semibold text-gray-800 mb-2">Sedang berjalan ({{ $menungguLain->count() }})</h3>
        <div class="space-y-2 mb-5">
            @foreach ($menungguLain as $t) {!! $baris($t) !!} @endforeach
        </div>
    @endif

    @if ($riwayat->isNotEmpty())
        <details class="mb-5">
            <summary class="cursor-pointer font-semibold text-gray-600 mb-2">Riwayat ({{ $riwayat->count() }})</summary>
            <div class="space-y-2 mt-2">
                @foreach ($riwayat as $t) {!! $baris($t) !!} @endforeach
            </div>
        </details>
    @endif
</div>
@endsection
