@extends('layouts.erp')

@section('content')
@php
    use App\Modules\Tasks\TugasOwner\Models\TugasOwner as Tugas;

    $labelPesan = [
        'brief' => ['📝', 'Brief', 'bg-blue-50 border-blue-200'],
        'ringkasan' => ['🧠', 'Yang ditangkap admin', 'bg-violet-50 border-violet-200'],
        'koreksi' => ['✏️', 'Koreksi owner', 'bg-amber-50 border-amber-200'],
        'setujui_ringkasan' => ['✅', 'Pemahaman disetujui', 'bg-emerald-50 border-emerald-200'],
        'hasil' => ['📤', 'Hasil pekerjaan', 'bg-sky-50 border-sky-200'],
        'revisi' => ['🔁', 'Minta revisi', 'bg-orange-50 border-orange-200'],
        'setujui_hasil' => ['🎉', 'Hasil disetujui', 'bg-emerald-50 border-emerald-200'],
        'komentar' => ['💬', 'Pesan', 'bg-white border-gray-200'],
        'batal' => ['🚫', 'Tugas dibatalkan', 'bg-gray-50 border-gray-200'],
    ];
    $warnaTahap = [
        Tugas::DRAF => 'bg-gray-100 text-gray-700', Tugas::RINGKASAN => 'bg-violet-100 text-violet-800', Tugas::CEK_RINGKASAN => 'bg-amber-100 text-amber-800',
        Tugas::DIKERJAKAN => 'bg-blue-100 text-blue-800', Tugas::PERIKSA => 'bg-orange-100 text-orange-800', Tugas::SELESAI => 'bg-emerald-100 text-emerald-800', Tugas::BATAL => 'bg-gray-200 text-gray-600',
    ];
    $bisa = fn (string $a) => in_array($a, $aksi, true);
    $komposer = compact('t', 'tersambung', 'potongan', 'maksMb');
@endphp
<div class="max-w-3xl mx-auto">
    <a href="{{ route('tasks.meja.tugas.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Tugas dari Owner</a>

    <div class="bg-white border border-gray-200 rounded-xl p-4 mt-2 mb-4">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <h2 class="text-lg font-bold text-gray-800">{{ $t->judul }}</h2>
            <span class="px-2 py-1 rounded text-xs font-bold {{ $warnaTahap[$t->tahap] }}">{{ Tugas::LABEL_TAHAP[$t->tahap] }}</span>
        </div>
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 mt-1">
            <span>{{ config('tugas_owner.jenis')[$t->jenis] ?? $t->jenis }}</span>
            <span>Untuk: <b class="text-gray-700">{{ $t->pengerja?->name }}</b></span>
            @if ($t->tenggat)<span class="{{ $t->lewatTenggat() ? 'text-red-600 font-bold' : '' }}">Tenggat {{ $t->tenggat->format('d/m/Y') }}{{ $t->lewatTenggat() ? ' (lewat)' : '' }}</span>@endif
            @if ($t->jumlah_revisi)<span>{{ $t->jumlah_revisi }}× revisi</span>@endif
            @unless ($t->wajib_periksa)<span>Tanpa pemeriksaan owner</span>@endunless
        </div>
    </div>

    <div class="space-y-3 mb-5">
        @forelse ($t->pesan as $p)
            @php $l = $labelPesan[$p->jenis] ?? ['💬', $p->jenis, 'bg-white border-gray-200']; @endphp
            <div class="border rounded-xl p-3 {{ $l[2] }}">
                <div class="flex items-center justify-between gap-2 text-xs">
                    <span class="font-bold text-gray-800">{{ $l[0] }} {{ $l[1] }}</span>
                    <span class="text-gray-500">{{ $p->user?->name }} · {{ $p->created_at->format('d/m H:i') }}</span>
                </div>
                @if ($p->isi)<p class="text-sm text-gray-800 whitespace-pre-line mt-1.5">{{ $p->isi }}</p>@endif
                @foreach ($p->lampiran as $b)
                    @php $url = route('tasks.meja.tugas.lampiran', $b->id); @endphp
                    <div class="mt-2">
                        @if ($b->jenis === 'video')
                            <video src="{{ $url }}" controls playsinline preload="metadata" class="w-full max-h-[70vh] rounded-lg bg-black"></video>
                        @elseif ($b->jenis === 'suara')
                            <audio src="{{ $url }}" controls preload="metadata" class="w-full"></audio>
                        @elseif ($b->jenis === 'gambar')
                            <a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="{{ $b->nama }}" loading="lazy" class="max-h-96 rounded-lg border border-gray-200"></a>
                        @endif
                        <a href="{{ route('tasks.meja.tugas.lampiran', [$b->id, 'unduh' => 1]) }}" class="text-[11px] text-gray-500 underline">⬇ {{ $b->nama }}</a>
                    </div>
                @endforeach
            </div>
        @empty
            <p class="text-sm text-gray-500 px-1">Brief belum dikirim. Admin belum melihat tugas ini.</p>
        @endforelse
    </div>

    <div class="space-y-4">
        @if ($bisa('brief'))
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <h3 class="font-semibold text-gray-800 mb-2">Kirim brief</h3>
                @include('erp.tasks.tugas-owner._composer', $komposer + ['aksiKirim' => 'brief', 'tombol' => 'Kirim brief ke admin', 'placeholder' => 'Catatan tambahan (opsional bila sudah ada rekaman): hasil yang diharapkan, syarat selesai, contoh…'])
            </div>
        @endif

        @if ($bisa('ringkasan'))
            <div class="bg-white border-2 border-violet-300 rounded-xl p-4">
                <h3 class="font-semibold text-gray-800">Tulis apa yang kamu tangkap</h3>
                <p class="text-xs text-gray-500 mb-2">Tonton briefnya, lalu tulis dengan kata-katamu: apa yang dikerjakan, hasilnya seperti apa, dan kapan selesai. Pekerjaan dimulai setelah owner menyetujui.</p>
                @include('erp.tasks.tugas-owner._composer', $komposer + ['aksiKirim' => 'ringkasan', 'tombol' => 'Kirim ke owner', 'placeholder' => 'Contoh: Upload Kotak Saran 2 Kotak ke Shopee dan Tokopedia, foto utama pakai latar kayu, tonjolkan gratis tulisan, selesai Jumat.', 'wajibTeks' => true, 'tanpaPerekam' => true, 'warna' => 'bg-violet-600 hover:bg-violet-700'])
            </div>
        @endif

        @if ($bisa('setujui_ringkasan'))
            <div class="bg-white border-2 border-amber-300 rounded-xl p-4 space-y-3">
                <h3 class="font-semibold text-gray-800">Sudah sesuai maksud Anda?</h3>
                <form method="POST" action="{{ route('tasks.meja.tugas.aksi', [$t->id, 'setujui_ringkasan']) }}">@csrf
                    <button class="px-4 py-2 rounded-lg text-sm font-semibold bg-emerald-600 hover:bg-emerald-700 text-white">✅ Sudah benar, kerjakan</button>
                </form>
                <details>
                    <summary class="cursor-pointer text-sm font-semibold text-amber-800">✏️ Belum tepat, koreksi</summary>
                    <div class="mt-2">@include('erp.tasks.tugas-owner._composer', $komposer + ['aksiKirim' => 'koreksi', 'tombol' => 'Kirim koreksi', 'placeholder' => 'Bagian mana yang belum tepat…', 'warna' => 'bg-amber-600 hover:bg-amber-700'])</div>
                </details>
            </div>
        @endif

        @if ($bisa('hasil'))
            <div class="bg-white border-2 border-sky-300 rounded-xl p-4">
                <h3 class="font-semibold text-gray-800 mb-2">Kirim hasil</h3>
                @include('erp.tasks.tugas-owner._composer', $komposer + ['aksiKirim' => 'hasil', 'tombol' => $t->wajib_periksa ? 'Kirim untuk diperiksa' : 'Kirim & selesai', 'placeholder' => 'Link hasil dan catatan pekerjaan…', 'warna' => 'bg-sky-600 hover:bg-sky-700'])
            </div>
        @endif

        @if ($bisa('setujui_hasil'))
            <div class="bg-white border-2 border-orange-300 rounded-xl p-4 space-y-3">
                <h3 class="font-semibold text-gray-800">Periksa hasil</h3>
                <form method="POST" action="{{ route('tasks.meja.tugas.aksi', [$t->id, 'setujui_hasil']) }}">@csrf
                    <button class="px-4 py-2 rounded-lg text-sm font-semibold bg-emerald-600 hover:bg-emerald-700 text-white">🎉 Setujui, tugas selesai</button>
                </form>
                <details>
                    <summary class="cursor-pointer text-sm font-semibold text-orange-800">🔁 Minta revisi</summary>
                    <div class="mt-2">@include('erp.tasks.tugas-owner._composer', $komposer + ['aksiKirim' => 'revisi', 'tombol' => 'Kirim revisi', 'placeholder' => 'Yang perlu diperbaiki…', 'warna' => 'bg-orange-600 hover:bg-orange-700'])</div>
                </details>
            </div>
        @endif

        @if ($bisa('komentar'))
            <details class="bg-white border border-gray-200 rounded-xl p-4">
                <summary class="cursor-pointer text-sm font-semibold text-gray-700">💬 Tanya atau kirim pesan</summary>
                <div class="mt-2">@include('erp.tasks.tugas-owner._composer', $komposer + ['aksiKirim' => 'komentar', 'tombol' => 'Kirim pesan', 'placeholder' => 'Tulis pesan…', 'warna' => 'bg-gray-700 hover:bg-gray-800'])</div>
            </details>
        @endif

        @if ($bisa('batal'))
            <form method="POST" action="{{ route('tasks.meja.tugas.aksi', [$t->id, 'batal']) }}" onsubmit="return confirm('Batalkan tugas ini?')" class="text-right">@csrf
                <button class="text-xs text-gray-400 hover:text-red-600 underline">Batalkan tugas</button>
            </form>
        @endif
    </div>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/tugas-owner.js') }}?v={{ @filemtime(public_path('js/tugas-owner.js')) }}"></script>
@endpush
