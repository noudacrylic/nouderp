@extends('layouts.erp')

@section('content')
@php
    $warnaMode = ['nonaktif' => 'bg-gray-100 text-gray-500', 'bayangan' => 'bg-amber-100 text-amber-800', 'otomatis' => 'bg-green-100 text-green-800'];
    $key = $skill->key();
    // Kategori skill ini di daftar prioritas meja (config/meja_prioritas.php) — lihat PrioritasMeja::kategori().
    $kategori = match ($key) {
        'retur'       => ['retur-24j', 'retur'],
        'tugas-owner' => ['owner-utama', 'owner-normal', 'owner-rendah'],
        default       => [$key],
    };
    $daftarMeja = $urutanPrioritas[$atur->peran] ?? null;
    $terdaftar = $daftarMeja !== null && array_intersect($kategori, $daftarMeja);
    $dataUrl = method_exists($skill, 'urlDataPengaturan') ? $skill->urlDataPengaturan($kode) : null;
    $dataLabel = ['desain' => 'Atur produk yang butuh desain →', 'purchasing-ready' => 'Atur min stok bahan (saran otomatis) →'][$key] ?? 'Atur data skill ini →';
@endphp
<div class="max-w-3xl mx-auto">
    <a href="{{ route('karyawan-virtual.show', $kode) }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← {{ $karyawan['ikon'] }} {{ $karyawan['nama'] }}</a>

    <form method="POST" action="{{ route('karyawan-virtual.admin.pengaturan.skill.simpan', [$kode, $key]) }}" class="bg-white shadow rounded-lg border p-6 space-y-5">
        @csrf @method('PUT')

        <div>
            <h2 class="text-xl font-bold text-gray-800">
                {{ $skill->label() }}
                <span class="ml-1 align-middle rounded px-1.5 py-0.5 text-[11px] font-semibold {{ $warnaMode[$mode] ?? '' }}">{{ $labelMode[$mode] ?? $mode }}</span>
            </h2>
            <p class="text-sm text-gray-500 mt-1">{{ $skill->deskripsi() }}</p>
            @unless ($skill->tersedia())<p class="text-xs text-amber-700 mt-1">Modul pendukungnya belum terpasang di server.</p>@endunless
            @if ($dataUrl)
                <a href="{{ $dataUrl }}" class="inline-block text-sm text-blue-600 hover:underline mt-2">{{ $dataLabel }}</a>
            @endif
        </div>

        <div class="grid sm:grid-cols-3 gap-4 text-sm">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Masuk meja</label>
                <select name="peran" class="w-full border rounded px-2 py-1.5">
                    @foreach ($mejaDiatur as $p => $label)<option value="{{ $p }}" @selected(old('peran', $atur->peran) === $p)>{{ $label }}</option>@endforeach
                </select>
                @error('peran')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Bobot (1–10)</label>
                <input type="number" min="1" max="10" name="bobot" value="{{ old('bobot', $atur->bobot) }}" class="w-full border rounded px-2 py-1.5">
                @error('bobot')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Tenggat (menit)</label>
                <input type="number" min="5" max="10080" name="sla_menit" value="{{ old('sla_menit', $atur->sla_menit) }}" class="w-full border rounded px-2 py-1.5">
                @error('sla_menit')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="text-xs text-gray-600 bg-gray-50 border rounded p-3 space-y-1">
            <p><b>Tenggat</b>: batas waktu kartu sejak pekerjaannya muncul (kecuali tenggatnya datang dari luar, mis. batas Shopee). Perubahan berlaku untuk kartu baru.</p>
            @if ($daftarMeja === null)
                <p><b>Bobot</b> menentukan urutan kartu di meja ini (bobot × urgensi tenggat).</p>
            @elseif ($terdaftar)
                <p>
                    <b>Bobot</b> saat ini
                    @if ($prioritasAktif)<b class="text-amber-700">tidak dipakai</b> di Meja {{ $mejaDiatur[$atur->peran] ?? $atur->peran }}: urutannya mengikuti daftar skill
                    <i>Prioritas &amp; pengganti admin</i> (kategori dulu, lalu tenggat terdekat). Bobot baru berlaku lagi bila skill itu Nonaktif.
                    @else dipakai (bobot × urgensi tenggat) karena skill <i>Prioritas &amp; pengganti admin</i> Nonaktif.
                    @endif
                </p>
            @else
                <p>
                    <b>Bobot</b>: skill ini belum ada di daftar <i>Prioritas &amp; pengganti admin</i>
                    @if ($prioritasAktif) — kartunya ditaruh sebelum Tugas Owner prioritas rendah, diurutkan dengan bobot ini di antara skill lain yang belum terdaftar.
                    @else ; selama skill itu Nonaktif urutannya bobot × urgensi tenggat.
                    @endif
                </p>
            @endif
        </div>

        <div class="flex justify-between items-center">
            <a href="{{ route('karyawan-virtual.admin.pengaturan', $kode) }}" class="text-xs text-blue-600 hover:underline">⚙ Pengaturan global (anggota meja)</a>
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-semibold">Simpan</button>
        </div>
    </form>
</div>
@endsection
