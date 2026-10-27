@extends('layouts.erp')

@section('content')
@php $fmt = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format($n, 3, ',', '.'), '0'), ','); @endphp
<div class="max-w-6xl mx-auto">
    <a href="{{ route('karyawan-virtual.show', $kode) }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← {{ $karyawan['ikon'] }} {{ $karyawan['nama'] }}</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-1">Saran Min Stok Bahan</h2>
        <p class="text-sm text-gray-500 mb-4">
            Bahan yang dibeli (pernah masuk PO, bukan hasil produksi). Saran = pemakaian per hari × (lama datang + hari cadangan).
            Lama datang diambil dari riwayat PO → faktur 180 hari; tanpa riwayat memakai lead time produk, atau {{ \App\Modules\Purchasing\Services\StokBahanService::LEAD_TIME_BAWAAN }} hari.
            Skill <b>Purchasing ready stok</b> {{ $karyawan['nama'] }} hanya memantau bahan yang min stoknya terisi.
        </p>

        <form method="GET" class="flex flex-wrap items-end gap-3 mb-4 text-sm">
            <label>Pemakaian
                <select name="hari" class="border rounded px-2 py-1.5 ml-1">
                    @foreach ([30, 60, 90, 180] as $h)<option value="{{ $h }}" @selected($hari == $h)>{{ $h }} hari terakhir</option>@endforeach
                </select>
            </label>
            <label>Cadangan
                <input type="number" name="cadangan" min="0" max="60" value="{{ $cadangan }}" class="border rounded px-2 py-1.5 w-20 ml-1"> hari
            </label>
            <button class="border border-gray-300 hover:bg-gray-50 px-3 py-1.5 rounded">Hitung ulang</button>
        </form>

        <form method="POST" action="{{ route('karyawan-virtual.admin.min-stok.simpan', $kode) }}">
            @csrf
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 text-xs">
                        <tr>
                            <th class="px-3 py-2 text-left">Bahan</th>
                            <th class="px-3 py-2 text-right">Tersedia</th>
                            <th class="px-3 py-2 text-right">Pakai / hari</th>
                            <th class="px-3 py-2 text-right">Lama datang</th>
                            <th class="px-3 py-2 text-right">Min sekarang</th>
                            <th class="px-3 py-2 text-right">Saran</th>
                            <th class="px-3 py-2 text-right w-36">Min baru</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($rows as $r)
                            @php $bawah = $r->min_stock !== null && $r->tersedia <= $r->min_stock; @endphp
                            <tr class="{{ $bawah ? 'bg-red-50' : '' }}">
                                <td class="px-3 py-2"><div class="font-medium text-gray-800">{{ $r->sku }}</div><div class="text-xs text-gray-500">{{ $r->name }}</div></td>
                                <td class="px-3 py-2 text-right {{ $bawah ? 'text-red-700 font-semibold' : '' }}">{{ $fmt($r->tersedia) }} <span class="text-xs text-gray-400">{{ $r->unit }}</span></td>
                                <td class="px-3 py-2 text-right">{{ $r->per_hari > 0 ? $fmt($r->per_hari) : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $fmt($r->lead) }} hr <span class="text-[11px] text-gray-400">{{ $r->lead_sumber }}</span></td>
                                <td class="px-3 py-2 text-right">{{ $r->min_stock !== null ? $fmt($r->min_stock) : '—' }}</td>
                                <td class="px-3 py-2 text-right text-blue-700">{{ $r->saran !== null ? $fmt($r->saran) : 'tak terpakai' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <input type="text" inputmode="decimal" name="min[{{ $r->id }}]" value="{{ $fmt($r->min_stock) }}" data-saran="{{ $fmt($r->saran) }}"
                                           class="min-in border rounded px-2 py-1 w-28 text-right" placeholder="kosong">
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">Belum ada bahan yang pernah dibeli lewat PO.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rows->isNotEmpty())
                <div class="flex justify-between items-center mt-4">
                    <button type="button" id="isiSaranKosong" class="border border-gray-300 hover:bg-gray-50 px-3 py-1.5 rounded text-xs">Isi saran ke yang masih kosong</button>
                    <button class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-semibold">Simpan</button>
                </div>
            @endif
        </form>
    </div>
</div>

<script>
document.getElementById('isiSaranKosong')?.addEventListener('click', () => {
    document.querySelectorAll('.min-in').forEach(el => {
        if (el.value.trim() === '' && el.dataset.saran) el.value = el.dataset.saran;
    });
});
</script>
@endsection
