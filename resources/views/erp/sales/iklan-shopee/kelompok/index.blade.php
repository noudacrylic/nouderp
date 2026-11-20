@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-1 gap-2 flex-wrap">
    <div class="flex items-center gap-2">
        <a href="{{ route('sales.iklan-shopee.index') }}" class="text-sm text-blue-700">← Iklan Shopee</a>
        <h1 class="text-lg font-semibold">Kelompok &amp; Jadwal Iklan</h1>
    </div>
    <a href="{{ route('sales.iklan-shopee.kelompok.create') }}" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">+ Kelompok baru</a>
</div>
<p class="text-xs text-gray-500 mb-3">
    Di <b>jam ramai</b> semua iklan anggota tayang; di <b>jam sepi</b> hanya beberapa yang tayang, bergiliran (yang ROAS-nya bagus lebih sering kebagian).
    Iklan yang stok listingnya habis tidak diberi giliran. Jadwal dijalankan tiap awal jam.
    Mode <b>Bayangan</b> hanya mencatat apa yang akan dilakukan — iklan di Shopee belum diubah.
    Aksi hari ini: {{ $aksiHariIni }} dari batas {{ $maksAksi }}/hari.
</p>

<div class="bg-white rounded shadow mb-3 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-600"><tr>
            <th class="text-left px-3 py-2">Kelompok</th>
            <th class="text-left px-2">Mode</th>
            <th class="text-right px-2">Anggota</th>
            <th class="text-left px-2">Jam ramai</th>
            <th class="text-left px-2">Jam sepi</th>
            <th class="text-right px-2" title="Perkiraan pause + lanjut per hari menurut rencana">Aksi/hari</th>
            <th class="text-right px-3">Tayang jam ini</th>
        </tr></thead>
        <tbody>
        @forelse ($kelompok as $k)
            <tr class="border-b border-gray-100 hover:bg-gray-50">
                <td class="px-3 py-2"><a href="{{ route('sales.iklan-shopee.kelompok.edit', $k) }}" class="text-blue-700 font-medium">{{ $k->nama }}</a></td>
                <td class="px-2">
                    <span class="text-xs rounded px-1.5 py-0.5 {{ $k->mode === 'bayangan' ? 'bg-amber-100 text-amber-800' : ($k->mode === 'otomatis' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500') }}">{{ ucfirst($k->mode) }}</span>
                </td>
                <td class="text-right px-2">{{ $k->iklan_count }}</td>
                <td class="px-2 text-xs">{{ $k->ringkasJamRamai() }}</td>
                <td class="px-2 text-xs">{{ $k->aktif_sepi }} iklan, ganti tiap {{ $k->rotasi_jam }} jam</td>
                <td class="text-right px-2">{{ $ringkas[$k->id]['aksi'] }}</td>
                <td class="text-right px-3">{{ $ringkas[$k->id]['aktif_kini'] }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada kelompok. Mulai dengan satu kelompok, misalnya "Tempat Brosur".</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="bg-white rounded shadow p-3 mb-3 text-sm">
    <div class="font-semibold mb-1">Uji perintah ke Shopee</div>
    <p class="text-xs text-gray-500 mb-2">Sebelum mode Otomatis dibuka, perintah jeda &amp; lanjut perlu dibuktikan ke iklan sungguhan. Tombol ini menjeda satu iklan yang sedang berjalan, menunggu 3 detik, lalu melanjutkannya lagi — status tiap langkah dibaca langsung dari Shopee. Pilih iklan bermodal kecil.</p>
    <form method="POST" action="{{ route('sales.iklan-shopee.uji-jeda') }}" class="flex gap-2 items-center flex-wrap"
          onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Menguji…';">
        @csrf
        <select name="campaign_id" required class="border rounded px-2 py-1.5 text-xs max-w-full w-[32rem]">
            @foreach ($calonUji as $c)
                <option value="{{ $c->campaign_id }}">Rp{{ number_format((float) $c->modal_harian, 0, ',', '.') }} · {{ \Illuminate\Support\Str::limit($c->nama, 80) }}</option>
            @endforeach
        </select>
        <button class="border border-amber-400 text-amber-800 bg-amber-50 px-3 py-1.5 rounded text-xs">Uji jeda &amp; lanjut</button>
    </form>
</div>

<div class="bg-white rounded shadow">
    <div class="px-3 py-2 border-b font-semibold text-sm">Catatan aksi terbaru</div>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50 text-gray-600"><tr>
                <th class="text-left px-3 py-1.5">Waktu</th><th class="text-left px-2">Kelompok</th><th class="text-left px-2">Iklan</th>
                <th class="text-left px-2">Aksi</th><th class="text-left px-2">Hasil</th><th class="text-left px-3">Alasan</th>
            </tr></thead>
            <tbody>
            @forelse ($log as $l)
                @include('erp.sales.iklan-shopee.kelompok._log_baris', ['l' => $l, 'nama' => $l->nama_iklan, 'kelompok' => $l->nama_kelompok, 'tampilKelompok' => true])
            @empty
                <tr><td colspan="6" class="px-3 py-4 text-center text-gray-400">Belum ada catatan.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
