@extends('layouts.erp')

@section('content')
@php
    $peranLabel = \App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran::PERAN;
    $warnaMode = ['nonaktif' => 'bg-gray-100 text-gray-500', 'bayangan' => 'bg-amber-100 text-amber-800', 'otomatis' => 'bg-green-100 text-green-800'];
@endphp
<div class="max-w-5xl mx-auto">
    <a href="{{ route('karyawan-virtual.show', $kode) }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← {{ $karyawan['ikon'] }} {{ $karyawan['nama'] }}</a>

    <form method="POST" action="{{ route('karyawan-virtual.admin.pengaturan.simpan', $kode) }}" class="space-y-4">
        @csrf @method('PUT')

        <div class="bg-white shadow rounded-lg border p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-1">Anggota meja</h2>
            <p class="text-sm text-gray-500 mb-4">
                Task skill masuk ke <b>meja peran</b>-nya (lihat kolom Peran di bawah) — tetap menumpuk di sana walau anggotanya belum ada.
                Anggota = akun yang membuka meja itu di Meja Kerja dan mengambil task-nya; siapa yang menekan Mulai, dialah pengerjanya.
                Super admin bisa membuka semua meja. Boleh kosong.
                Yang tampil: super admin/admin, divisi Admin, dan user yang diberi menu Meja Kerja (Pengaturan › User).
            </p>
            <div class="grid md:grid-cols-3 gap-4">
                @foreach ($peranLabel as $p => $label)
                    @php $isi = ($peran[$p] ?? collect())->pluck('user_id')->all(); @endphp
                    <div class="border rounded p-3">
                        <div class="font-semibold text-gray-800 mb-2">Meja {{ $label }}</div>
                        <input type="hidden" name="peran[{{ $p }}][]" value="">
                        <div class="max-h-56 overflow-y-auto space-y-1">
                            @foreach ($users as $u)
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="peran[{{ $p }}][]" value="{{ $u->id }}" @checked(in_array($u->id, $isi))>
                                    {{ $u->name }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="bg-white shadow rounded-lg border p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-1">Skill</h2>
            <p class="text-sm text-gray-500 mb-4">
                Nyalakan skill lewat mode di kartu skill halaman {{ $karyawan['nama'] }}: <b>Bayangan</b> hanya mencatat task yang akan dibuat,
                <b>Otomatis</b> benar-benar membuatnya — hanya pekerjaan <b>sejak mode diganti</b> yang masuk. Bobot 1–10 menentukan urutan (dikali urgensi tenggat).
            </p>
            <div class="divide-y border rounded">
                @foreach ($skills as $key => $row)
                    @php $s = $row['skill']; $a = $row['atur']; @endphp
                    <div id="skill-{{ $key }}" class="p-3 grid md:grid-cols-12 gap-3 items-center text-sm scroll-mt-20">
                        <div class="md:col-span-5">
                            <div class="font-semibold text-gray-800">
                                {{ $s->label() }}
                                <span class="ml-1 rounded px-1.5 py-0.5 text-[11px] font-semibold {{ $warnaMode[$row['mode']] ?? '' }}">{{ $labelMode[$row['mode']] ?? $row['mode'] }}</span>
                            </div>
                            <div class="text-xs text-gray-500 mt-0.5">{{ $s->deskripsi() }}</div>
                            @unless ($s->tersedia())<div class="text-xs text-amber-700 mt-0.5">Modul pendukungnya belum terpasang di server.</div>@endunless
                            @if ($key === 'desain')
                                <a href="{{ $s->urlPengaturan($kode) }}" class="inline-block text-xs text-blue-600 hover:underline mt-0.5">Atur produk yang butuh desain →</a>
                            @endif
                            @if ($key === 'purchasing-ready')
                                <a href="{{ $s->urlPengaturan($kode) }}" class="inline-block text-xs text-blue-600 hover:underline mt-0.5">Atur min stok bahan (saran otomatis) →</a>
                                <div class="text-[11px] text-gray-500">Selama Otomatis, aturan Task Otomasi jenis stok tidak membuat task lagi.</div>
                            @endif
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-[11px] text-gray-500">Masuk meja</label>
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
