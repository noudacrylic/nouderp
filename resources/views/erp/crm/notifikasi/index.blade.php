@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Notifikasi Pesanan</h1>
    <div class="flex gap-2">
        <a href="{{ route('crm.inbox.index') }}" class="border border-gray-300 hover:bg-gray-50 px-3 py-2 rounded text-sm">← Inbox</a>
        <form method="POST" action="{{ route('crm.notifikasi.kirim') }}">
            @csrf
            <button class="border border-blue-600 text-blue-600 hover:bg-blue-50 px-3 py-2 rounded text-sm">Kirim yang Jatuh Tempo</button>
        </form>
    </div>
</div>

@if($dryRun)
    <div class="mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
        <b>Mode aman menyala.</b> Notifikasi ditandai terkirim untuk menguji alurnya, tapi tidak ada yang sampai ke pelanggan.
    </div>
@endif

{{-- Katalog jenis notifikasi: apa saja yang dikirim ERP, bunyinya bagaimana,
     aktif atau tidak, dan berapa yang sudah berangkat. Teks contohnya dirangkai
     dari sumber yang SAMA dengan yang benar-benar dikirim, jadi tidak mungkin
     melenceng diam-diam dari kalimat yang diterima pelanggan. --}}
<form method="POST" action="{{ route('crm.notifikasi.jenis') }}" class="bg-white rounded shadow p-4 mb-3">
    @csrf
    <div class="flex items-center justify-between mb-3">
        <div>
            <h2 class="text-sm font-bold uppercase tracking-wider text-gray-500">Jenis Notifikasi</h2>
            <p class="text-xs text-gray-400 mt-0.5">
                Semua yang dikirim sistem sendiri, berikut pengajuan templatenya ke Meta.
                Bunyinya tidak bisa diubah dari layar: satu kalimat berangkat lewat dua jalur
                (WAHA &amp; resmi) dan wajib sama persis. Kalimat yang Anda pilih sendiri saat chat
                ada di <a href="{{ route('crm.template.index') }}" class="text-blue-600 underline">Template Pesan</a>.
            </p>
        </div>
        <button class="border border-gray-300 hover:bg-gray-50 px-3 py-1.5 rounded text-xs font-semibold text-gray-700">
            Simpan Saklar
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
        @foreach($jenis as $j)
            <div class="border rounded-lg p-3 {{ $j['aktif'] ? 'border-gray-200' : 'border-gray-200 bg-gray-50' }}">
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="checkbox" name="aktif[{{ $j['event'] }}]" value="1"
                           {{ $j['aktif'] ? 'checked' : '' }}
                           class="rounded border-gray-300 mt-0.5">
                    <span class="min-w-0">
                        <span class="block font-semibold text-sm text-gray-800">{{ $j['label'] }}</span>
                        <span class="block text-xs text-gray-500 mt-0.5">{{ $j['pemicu'] }}</span>
                    </span>
                </label>

                <div class="mt-3 rounded bg-emerald-50 border border-emerald-100 px-3 py-2 text-xs text-gray-700 whitespace-pre-line leading-relaxed">{{ $j['teks'] }}</div>

                {{-- Status template Meta + jalan mengajukannya, di tempat bunyinya
                     terbaca. Selama ini pengajuan hidup di layar lain, dan
                     akibatnya orang membaca bunyi di sini lalu harus mengingat
                     nama templatenya untuk dicari di sana. --}}
                <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px]">
                    @if($j['ke_meta'])
                        <span class="px-1.5 py-0.5 rounded-full
                            {{ $j['status_meta'] === 'APPROVED' ? 'bg-green-100 text-green-700'
                               : ($j['status_meta'] === 'REJECTED' ? 'bg-red-100 text-red-700'
                               : ($j['status_meta'] ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600')) }}"
                              title="Nama template di Meta: {{ $j['template'] }}">
                            Meta: {{ $j['status_meta'] ?: 'belum diajukan' }}
                        </span>

                        @if($j['wajib_resmi'])
                            <span class="px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-800"
                                  title="Tak punya jalur cadangan — kalau templatenya belum disetujui, pesannya gagal, bukan dialihkan ke WAHA">
                                wajib jalur resmi
                            </span>
                        @endif

                        @unless($j['status_meta'])
                            {{-- Tombolnya menumpang form di luar: <form> di dalam
                                 <form> bukan HTML yang sah, dan peramban akan
                                 membuang salah satunya diam-diam. --}}
                            <button type="submit" form="ajukan-{{ $j['event'] }}"
                                    class="px-2 py-0.5 rounded border border-emerald-600 text-emerald-700 hover:bg-emerald-50">
                                Ajukan ke Meta
                            </button>
                        @endunless
                    @else
                        <span class="px-1.5 py-0.5 rounded-full bg-violet-100 text-violet-700"
                              title="Nadanya akan digolongkan MARKETING, dan penggolongan itu menempel pada nomornya">
                            khusus WAHA — jangan diajukan
                        </span>
                    @endif
                </div>

                <div class="mt-2 flex flex-wrap gap-1.5 text-[11px]">
                    <span class="px-1.5 py-0.5 rounded bg-green-100 text-green-700">{{ $j['hitungan']['terkirim'] }} terkirim</span>
                    <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">{{ $j['hitungan']['menunggu'] }} menunggu</span>
                    <span class="px-1.5 py-0.5 rounded bg-red-100 text-red-700">{{ $j['hitungan']['gagal'] }} gagal</span>
                    <span class="px-1.5 py-0.5 rounded bg-gray-200 text-gray-600">{{ $j['hitungan']['dilewati'] }} dilewati</span>
                </div>

                @unless($j['aktif'])
                    <p class="mt-2 text-[11px] text-gray-500">
                        Dimatikan. Pesanan yang memicunya tetap dicatat sebagai <b>dilewati</b> beserta alasannya —
                        tidak hilang tanpa jejak.
                    </p>
                @endunless
            </div>
        @endforeach
    </div>
</form>

{{-- Form pengajuan tiap jenis. Berdiri di luar form saklar di atas dan
     dirujuk lewat atribut form="…" — form bersarang bukan HTML yang sah. --}}
@foreach($jenis as $j)
    @if($j['ke_meta'] && ! $j['status_meta'])
        <form id="ajukan-{{ $j['event'] }}" method="POST" action="{{ route('crm.notifikasi.template.ajukan') }}"
              onsubmit="return confirm('Ajukan template &quot;{{ $j['template'] }}&quot; ke Meta? Bunyinya tidak bisa diubah dari ERP setelah diajukan.')">
            @csrf
            <input type="hidden" name="event" value="{{ $j['event'] }}">
        </form>
    @endif
@endforeach

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['menunggu' => 'Menunggu', 'terkirim' => 'Terkirim', 'gagal' => 'Gagal', 'dilewati' => 'Dilewati'] as $val => $label)
                <option value="{{ $val }}" @selected(request('status')===$val)>{{ $label }} ({{ $jumlah[$val] ?? 0 }})</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Jenis</label>
        <select name="event" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="pembayaran_diterima" @selected(request('event')==='pembayaran_diterima')>Pembayaran Diterima</option>
            <option value="siap_diambil" @selected(request('event')==='siap_diambil')>Siap Diambil</option>
            <option value="dikirim" @selected(request('event')==='dikirim')>Dikirim</option>
        </select>
    </div>
    @include('erp._partials.per-page-select')
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Pesanan</th>
                <th class="px-3 py-2 text-left">Jenis</th>
                <th class="px-3 py-2 text-left">Tujuan</th>
                <th class="px-3 py-2 text-left">Jadwal / Terkirim</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-right w-28">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($daftar as $n)
                @php
                    $stCls = match($n->status) {
                        'terkirim' => 'bg-green-100 text-green-700',
                        'gagal'    => 'bg-red-100 text-red-700',
                        'dilewati' => 'bg-gray-100 text-gray-600',
                        default    => 'bg-yellow-100 text-yellow-700',
                    };
                @endphp
                <tr class="border-b">
                    <td class="px-3 py-2 whitespace-nowrap">{{ $n->salesOrder->order_number ?? '—' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $n->event }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $n->recipient ?: '—' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                        @if($n->sent_at)
                            {{ $n->sent_at->translatedFormat('d M Y H:i') }}
                        @elseif($n->scheduled_at)
                            dijadwalkan {{ $n->scheduled_at->translatedFormat('d M Y H:i') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <span class="px-2 py-0.5 rounded text-xs {{ $stCls }}">{{ $n->status }}</span>
                        {{-- Alasan adalah inti layar ini: menjawab "kenapa pelanggan
                             ini tidak dapat kabar?" tanpa membuka basis data. --}}
                        @if($n->reason)
                            <div class="text-xs text-gray-500 mt-0.5">{{ $n->reason }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        @if($n->status === 'gagal')
                            <form method="POST" action="{{ route('crm.notifikasi.ulangi', $n->id) }}">
                                @csrf
                                <button class="border border-blue-600 text-blue-600 hover:bg-blue-50 px-2 py-1 rounded text-xs">Coba Lagi</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Belum ada notifikasi.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $daftar->links() }}</div>
@endsection
