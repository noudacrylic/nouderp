@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Label Percakapan</h1>
    <a href="{{ route('crm.inbox.index') }}" class="text-sm text-emerald-700 hover:underline">← Kembali ke Inbox</a>
</div>

<div class="mb-4 rounded border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700">
    Label ini yang muncul sebagai baris tombol di kepala daftar chat dan di panel Info.
    Mengganti namanya <b>tidak</b> mengubah chat yang sudah berlabel itu — yang berganti hanya tulisannya.
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <div class="lg:col-span-2 bg-white rounded shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                <tr>
                    <th class="text-left px-3 py-2">Nama</th>
                    <th class="text-left px-3 py-2">Warna</th>
                    <th class="text-left px-3 py-2 w-20">Urutan</th>
                    <th class="text-left px-3 py-2 w-20">Aktif</th>
                    <th class="text-right px-3 py-2 w-40">Dipakai</th>
                    <th class="px-3 py-2 w-28"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($labels as $l)
                    {{-- Formulir per baris ditaruh di luar tabel dan diikat lewat
                         atribut form=: <form> yang membentang antar <td> dibuang
                         peramban, dan tombol Simpan-nya jadi mati diam-diam. --}}
                    <tr>
                        <td class="px-3 py-2">
                            <input type="text" form="label-{{ $l->id }}" name="nama" value="{{ $l->nama }}" maxlength="60" required
                                   class="border rounded px-2 py-1 text-sm w-full">
                            <div class="mt-1 text-[10px] text-gray-400 font-mono">{{ $l->kode }}</div>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <select form="label-{{ $l->id }}" name="warna" class="border rounded px-2 py-1 text-sm">
                                @foreach(\App\Modules\CRM\Models\CrmLabel::NAMA_WARNA as $kode => $arti)
                                    <option value="{{ $kode }}" @selected($l->warna === $kode)>{{ $arti }}</option>
                                @endforeach
                            </select>
                            <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] {{ $l->kelasChip() }}">{{ $l->nama }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <input type="number" form="label-{{ $l->id }}" name="urutan" value="{{ $l->urutan }}" min="0" max="999"
                                   class="border rounded px-2 py-1 text-sm w-16">
                        </td>
                        <td class="px-3 py-2">
                            <input type="hidden" form="label-{{ $l->id }}" name="aktif" value="0">
                            <input type="checkbox" form="label-{{ $l->id }}" name="aktif" value="1" @checked($l->aktif)>
                        </td>
                        <td class="px-3 py-2 text-right text-gray-600">{{ $jumlah[$l->kode] ?? 0 }} chat</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <button form="label-{{ $l->id }}"
                                    class="border border-emerald-600 text-emerald-700 hover:bg-emerald-50 px-2 py-1 rounded text-xs">Simpan</button>
                            @if(($jumlah[$l->kode] ?? 0) === 0)
                                <button form="hapus-label-{{ $l->id }}" class="text-xs text-red-600 hover:underline ml-1">Hapus</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Belum ada label.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Wadah formulir baris. Kosong secara visual; hanya rumah bagi tombol
             dan isian di atas yang menunjuk ke sini lewat atribut form=. --}}
        @foreach($labels as $l)
            <form method="POST" action="{{ route('crm.label.update', $l->id) }}" id="label-{{ $l->id }}" class="hidden">@csrf</form>
            <form method="POST" action="{{ route('crm.label.destroy', $l->id) }}" id="hapus-label-{{ $l->id }}" class="hidden"
                  onsubmit="return confirm('Hapus label {{ $l->nama }}?')">@csrf @method('DELETE')</form>
        @endforeach
    </div>

    <div class="bg-white rounded shadow p-3 h-fit">
        <h2 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-2">Tambah Label</h2>
        <form method="POST" action="{{ route('crm.label.store') }}" class="space-y-2">
            @csrf
            <input type="text" name="nama" required maxlength="60" placeholder="Nama label, mis. Menunggu Bayar"
                   class="border rounded w-full px-2 py-1.5 text-sm">
            <select name="warna" class="border rounded w-full px-2 py-1.5 text-sm">
                @foreach(\App\Modules\CRM\Models\CrmLabel::NAMA_WARNA as $kode => $arti)
                    <option value="{{ $kode }}" @selected($kode === 'gray')>{{ $arti }}</option>
                @endforeach
            </select>
            <button class="w-full border border-emerald-600 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded text-sm">Tambah</button>
        </form>
        <p class="mt-2 text-[11px] text-gray-500 leading-relaxed">
            Label yang sudah dipakai chat tidak bisa dihapus — nonaktifkan saja.
            Yang nonaktif hilang dari pilihan, tapi chat lama tetap menampilkan namanya.
        </p>
    </div>
</div>
@endsection
