@extends('layouts.erp')

@section('content')
<div class="max-w-5xl mx-auto">
    <a href="{{ route('karyawan-virtual.show', $kode) }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← {{ $karyawan['ikon'] }} {{ $karyawan['nama'] }}</a>

    <form method="POST" action="{{ route('karyawan-virtual.admin.pengaturan.simpan', $kode) }}" class="space-y-4">
        @csrf @method('PUT')

        <div class="bg-white shadow rounded-lg border p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-1">Anggota meja</h2>
            <p class="text-sm text-gray-500 mb-4">
                Task skill masuk ke <b>meja peran</b>-nya (diatur di pengaturan masing-masing skill) — tetap menumpuk di sana walau anggotanya belum ada.
                Anggota = akun yang membuka meja itu di Meja Kerja dan mengambil task-nya; siapa yang menekan Mulai, dialah pengerjanya.
                Super admin bisa membuka semua meja. Boleh kosong.
                Yang tampil: super admin/admin, divisi Admin, dan user yang diberi menu Meja Kerja (Pengaturan › User).
            </p>
            <div class="grid md:grid-cols-3 gap-4">
                @foreach ($mejaDiatur as $p => $label)
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

        <p class="text-xs text-gray-500 px-1">
            Meja tujuan, bobot, dan tenggat diatur per skill lewat tombol <b>⚙ Pengaturan</b> di kartu skill halaman {{ $karyawan['nama'] }}.
        </p>

        <div class="text-right">
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-semibold">Simpan</button>
        </div>
    </form>
</div>
@endsection
