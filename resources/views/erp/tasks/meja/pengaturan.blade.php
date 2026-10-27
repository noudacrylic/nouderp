@extends('layouts.erp')

@section('content')
@php $peranLabel = \App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran::PERAN; @endphp
<div class="max-w-5xl mx-auto">
    <a href="{{ route('tasks.meja.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Meja Kerja</a>

    <form method="POST" action="{{ route('tasks.meja.pengaturan.simpan') }}" class="space-y-4">
        @csrf @method('PUT')

        <div class="bg-white shadow rounded-lg border p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-1">Peran</h2>
            <p class="text-sm text-gray-500 mb-4">
                Orang pertama = utama, berikutnya cadangan. Task Antre otomatis pindah ke cadangan bila yang utama belum absen masuk hari itu
                (user tanpa data karyawan, mis. owner, selalu dianggap hadir).
            </p>
            <div class="grid md:grid-cols-3 gap-4">
                @foreach ($peranLabel as $p => $label)
                    @php $isi = ($peran[$p] ?? collect())->pluck('user_id')->all(); @endphp
                    <div class="border rounded p-3">
                        <div class="font-semibold text-gray-800 mb-2">{{ $label }}</div>
                        @for ($i = 0; $i < 3; $i++)
                            <label class="block text-[11px] text-gray-500 mt-1">{{ $i === 0 ? 'Utama' : 'Cadangan ' . $i }}</label>
                            <select name="peran[{{ $p }}][]" class="w-full border rounded px-2 py-1.5 text-sm">
                                <option value="">—</option>
                                @foreach ($users as $u)<option value="{{ $u->id }}" @selected(($isi[$i] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
                            </select>
                        @endfor
                    </div>
                @endforeach
            </div>
        </div>

        <div class="bg-white shadow rounded-lg border p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-1">Skill Admin</h2>
            <p class="text-sm text-gray-500 mb-4">
                Skill membuat task sendiri dari pekerjaan yang muncul di ERP. Saat dinyalakan, hanya pekerjaan <b>sejak saat itu</b> yang masuk —
                pekerjaan lama tidak membanjiri meja. Bobot 1–10 menentukan urutan (dikali urgensi tenggat).
            </p>
            <div class="divide-y border rounded">
                @foreach ($skills as $key => $row)
                    @php $s = $row['skill']; $a = $row['atur']; @endphp
                    <div class="p-3 grid md:grid-cols-12 gap-3 items-center text-sm">
                        <div class="md:col-span-5">
                            <label class="inline-flex items-center gap-2 font-semibold text-gray-800">
                                <input type="hidden" name="skill[{{ $key }}][aktif]" value="0">
                                <input type="checkbox" name="skill[{{ $key }}][aktif]" value="1" @checked($a->aktif) @disabled(!$s->tersedia())>
                                {{ $s->label() }}
                            </label>
                            <div class="text-xs text-gray-500 mt-0.5">{{ $s->deskripsi() }}</div>
                            @unless ($s->tersedia())<div class="text-xs text-amber-700 mt-0.5">Modul pendukungnya belum terpasang di server.</div>@endunless
                            @if ($a->aktif && $a->aktif_sejak)<div class="text-[11px] text-green-700 mt-0.5">Aktif sejak {{ $a->aktif_sejak->format('d/m/Y H:i') }}</div>@endif
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-[11px] text-gray-500">Peran</label>
                            <select name="skill[{{ $key }}][peran]" class="w-full border rounded px-2 py-1.5">
                                @foreach ($peranLabel as $p => $label)<option value="{{ $p }}" @selected($a->peran === $p)>{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[11px] text-gray-500">Bobot</label>
                            <input type="number" min="1" max="10" name="skill[{{ $key }}][bobot]" value="{{ $a->bobot }}" class="w-full border rounded px-2 py-1.5">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[11px] text-gray-500">Tenggat (menit)</label>
                            <input type="number" min="5" name="skill[{{ $key }}][sla_menit]" value="{{ $a->sla_menit }}" class="w-full border rounded px-2 py-1.5">
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="text-right">
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-semibold">Simpan</button>
        </div>
    </form>
</div>
@endsection
