@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Template Pesan</h1>
    <a href="{{ route('crm.template.baru') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">Chat Baru</a>
</div>

<div class="mb-4 rounded border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700">
    Template dipakai untuk menghubungi nomor yang <b>belum</b> menghubungi kita dalam 24 jam terakhir.
    Di dalam jendela 24 jam, balasan biasa gratis dan tidak butuh template.
</div>

{{-- ------------------------------------------------ terdaftar di Meta --}}
<h2 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-2">Terdaftar di Meta</h2>

@if(! $daftar['success'])
    <div class="mb-6 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
        Tidak bisa membaca daftar template: {{ $daftar['error'] }}
    </div>
@elseif(empty($daftar['templates']))
    <div class="mb-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
        Belum ada satu pun template terdaftar. Ajukan lewat dasbor api.co.id memakai bunyi di bawah,
        lalu daftar ini terisi sendiri.
    </div>
@else
    <div class="bg-white rounded shadow overflow-x-auto mb-6">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                <tr>
                    <th class="text-left px-3 py-2">Nama</th>
                    <th class="text-left px-3 py-2">Status</th>
                    <th class="text-left px-3 py-2">Kategori</th>
                    <th class="text-left px-3 py-2">Bahasa</th>
                    <th class="text-left px-3 py-2">Isian</th>
                    <th class="text-left px-3 py-2">Bunyi</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @foreach($daftar['templates'] as $t)
                    <tr>
                        <td class="px-3 py-2 font-medium">{{ $t['name'] }}</td>
                        <td class="px-3 py-2">
                            <span class="px-2 py-0.5 rounded-full text-xs
                                {{ $t['status'] === 'APPROVED' ? 'bg-green-100 text-green-700'
                                   : ($t['status'] === 'REJECTED' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800') }}">
                                {{ $t['status'] }}
                            </span>
                        </td>
                        <td class="px-3 py-2">{{ $t['category'] ?: '—' }}</td>
                        <td class="px-3 py-2">{{ $t['language'] }}</td>
                        <td class="px-3 py-2">{{ $t['variables'] }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $t['body'] ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- ------------------------------------------------------- usulan siap ajukan --}}
<h2 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-2">Bunyi yang sudah disepakati</h2>
<p class="text-xs text-gray-500 mb-3">
    Salin apa adanya ke dasbor vendor. Semua sudah mematuhi aturan Meta: tidak diawali/diakhiri variabel,
    tidak ada dua variabel berdampingan, tanpa nada promosi (satu kalimat promosi = direklasifikasi MARKETING).
</p>

<div class="space-y-3">
    @foreach($usulan as $u)
        <div class="bg-white rounded shadow p-3">
            <div class="flex flex-wrap items-center gap-2 mb-1">
                <span class="font-mono text-sm font-semibold">{{ $u['nama'] }}</span>
                <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 text-xs">{{ $u['kategori'] }}</span>
                <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 text-xs">id</span>
            </div>
            <p class="text-xs text-gray-500 mb-2">{{ $u['guna'] }}</p>
            <textarea readonly rows="3" onclick="this.select()"
                      class="w-full border rounded px-3 py-2 text-sm bg-gray-50">{{ $u['body'] }}</textarea>
        </div>
    @endforeach
</div>
@endsection
