@extends('layouts.erp')

@section('content')
@php
    $judulKolom = [
        'open'        => ['Antre', 'bg-slate-500', 'Urut skor — yang teratas dikerjakan dulu'],
        'in_progress' => ['Dikerjakan', 'bg-blue-600', 'Maks ' . \App\Modules\Tasks\MejaKerja\MejaKerjaService::MAKS_DIKERJAKAN . ' per orang'],
        'pending'     => ['Pending', 'bg-orange-500', 'Menunggu pelanggan/bahan/keputusan'],
        'done'        => ['Selesai hari ini', 'bg-green-600', ''],
    ];
    $peranLabel = \App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran::PERAN;
    $alasanCepat = ['Menunggu balasan pelanggan', 'Menunggu bahan', 'Menunggu keputusan owner'];
@endphp
<div class="max-w-[1600px] mx-auto">
    <div class="flex items-start justify-between gap-3 flex-wrap mb-4">
        <div>
            <h2 class="text-xl font-bold text-gray-800">Meja Kerja</h2>
            <p class="text-sm text-gray-500">
                @if ($peranSaya)
                    Peran Anda: {{ collect($peranSaya)->map(fn ($p) => $peranLabel[$p] ?? $p)->implode(', ') }}.
                @else
                    Anda belum punya peran — task yang ditugaskan langsung ke Anda tetap muncul di sini.
                @endif
                Task otomatis selesai saat pekerjaannya tuntas di ERP.
            </p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            @if ($bolehSemua)
                <form method="GET" class="flex items-center gap-1">
                    <select name="lihat" onchange="this.form.submit()" class="border rounded px-2 py-2 text-sm">
                        <option value="saya" @selected($lihat === 'saya')>Meja saya</option>
                        <option value="semua" @selected($lihat === 'semua')>Semua peran</option>
                        @foreach ($peranLabel as $k => $l)<option value="{{ $k }}" @selected($lihat === $k)>{{ $l }}</option>@endforeach
                    </select>
                </form>
                <form method="POST" action="{{ route('tasks.meja.sinkron') }}">@csrf
                    <button class="border text-gray-600 hover:bg-gray-50 px-3 py-2 rounded text-sm">Sinkron sekarang</button>
                </form>
                <a href="{{ route('karyawan-virtual.show', \App\Modules\Tasks\MejaKerja\MejaKerjaService::KARYAWAN) }}" class="border text-gray-600 hover:bg-gray-50 px-3 py-2 rounded text-sm" title="Skill, peran & jurnal pembagian kerja">🗂️ Manajer Admin</a>
            @endif
            <form method="POST" action="{{ route('tasks.meja.ambil') }}">@csrf
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-semibold"
                        @disabled($dikerjakanSaya >= \App\Modules\Tasks\MejaKerja\MejaKerjaService::MAKS_DIKERJAKAN)>▶ Ambil berikutnya</button>
            </form>
        </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 items-start">
        @foreach ($kolom as $status => $tasks)
            <div class="bg-slate-100 rounded-lg overflow-hidden">
                <div class="{{ $judulKolom[$status][1] }} text-white px-3 py-2 flex items-center justify-between">
                    <span class="font-bold text-sm uppercase tracking-wide">{{ $judulKolom[$status][0] }}</span>
                    <span class="text-xs bg-white/25 rounded-full px-2">{{ $tasks->count() }}</span>
                </div>
                @if ($judulKolom[$status][2])<div class="px-3 pt-2 text-[11px] text-gray-500">{{ $judulKolom[$status][2] }}</div>@endif
                <div class="p-2 space-y-2 max-h-[75vh] overflow-y-auto">
                    @forelse ($tasks as $t)
                        @php
                            $skill = $skills[$t->skill] ?? null;
                            $lewat = $t->tenggat_at && $t->tenggat_at->isPast() && !in_array($t->status, ['done', 'cancelled'], true);
                        @endphp
                        <div class="bg-white rounded-md border {{ $lewat ? 'border-red-300' : 'border-gray-200' }} p-3 text-sm">
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-semibold text-gray-800 leading-snug">{{ $t->title }}</div>
                                @if ($status === 'open')<span class="text-[10px] text-gray-400 whitespace-nowrap">skor {{ $skor[$t->id] }}</span>@endif
                            </div>
                            <div class="flex flex-wrap gap-1 mt-1 text-[10px]">
                                <span class="px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 font-semibold">{{ $skill?->label() ?? 'Manual' }}</span>
                                @if ($t->peran)<span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">{{ $peranLabel[$t->peran] ?? $t->peran }}</span>@endif
                                <span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">{{ $t->assignee?->name ?? 'Belum dipegang' }}</span>
                                @if ($t->tenggat_at && $status !== 'done')
                                    <span class="px-1.5 py-0.5 rounded {{ $lewat ? 'bg-red-100 text-red-700 font-bold' : 'bg-amber-50 text-amber-700' }}">
                                        {{ $lewat ? 'Lewat ' . $t->tenggat_at->diffForHumans(null, true) : 'Tenggat ' . $t->tenggat_at->format('d/m H:i') }}
                                    </span>
                                @endif
                            </div>
                            @if ($status === 'pending' && $t->pending_alasan)
                                <div class="mt-2 text-xs text-orange-700 bg-orange-50 rounded px-2 py-1">⏸ {{ $t->pending_alasan }} · sejak {{ $t->pending_sejak?->format('d/m H:i') }}</div>
                            @endif
                            @if ($t->description)
                                <details class="mt-2 text-xs text-gray-600"><summary class="cursor-pointer text-gray-400">Rincian</summary>
                                    <div class="whitespace-pre-line mt-1">{{ $t->description }}</div>
                                </details>
                            @endif

                            @if ($skill && $status !== 'done')
                                <div class="flex flex-wrap gap-1 mt-2">
                                    @foreach ($skill->aksi($t) as $a)
                                        <a href="{{ $a['url'] ?? '#' }}" @if (!empty($a['url'])) target="_blank" rel="noopener" @endif
                                           @if (!empty($a['salin'])) data-salin="{{ $a['salin'] }}" @endif
                                           class="meja-aksi px-2 py-1 rounded text-xs font-semibold {{ !empty($a['utama']) ? 'bg-orange-500 text-white hover:bg-orange-600' : 'border border-gray-300 text-gray-700 hover:bg-gray-50' }}">{{ $a['label'] }}</a>
                                    @endforeach
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center gap-1 mt-2 pt-2 border-t border-gray-100">
                                @if ($status === 'open')
                                    <form method="POST" action="{{ route('tasks.meja.mulai', $t->id) }}">@csrf
                                        <button class="bg-blue-600 text-white px-2 py-1 rounded text-xs font-semibold">▶ Mulai</button>
                                    </form>
                                @elseif ($status === 'in_progress')
                                    @if (!$skill || $skill->selesaiManual())
                                        <form method="POST" action="{{ route('tasks.meja.selesai', $t->id) }}">@csrf
                                            <button class="bg-green-600 text-white px-2 py-1 rounded text-xs font-semibold">✓ Selesai</button>
                                        </form>
                                    @endif
                                    @if ($skill instanceof \App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban && ($jawaban = $skill->jawaban($t)))
                                        <form method="POST" action="{{ route('tasks.meja.jawab', $t->id) }}" class="flex flex-wrap gap-1 w-full mb-1">@csrf
                                            @foreach ($jawaban as $j)
                                                <button name="jawaban" value="{{ $j['kunci'] }}"
                                                        class="px-2 py-1 rounded text-xs font-semibold {{ !empty($j['utama']) ? 'bg-green-600 text-white' : 'border border-green-300 text-green-800 hover:bg-green-50' }}">{{ $j['label'] }}</button>
                                            @endforeach
                                        </form>
                                    @endif
                                    <details class="relative">
                                        <summary class="list-none cursor-pointer border border-orange-300 text-orange-700 px-2 py-1 rounded text-xs font-semibold">⏸ Pending</summary>
                                        <form method="POST" action="{{ route('tasks.meja.pending', $t->id) }}" class="absolute z-10 mt-1 w-64 bg-white border rounded shadow p-2 space-y-1">@csrf
                                            @foreach ($alasanCepat as $al)
                                                <button name="alasan" value="{{ $al }}" class="block w-full text-left text-xs px-2 py-1 rounded hover:bg-orange-50">{{ $al }}</button>
                                            @endforeach
                                            <div class="flex gap-1 pt-1 border-t">
                                                <input name="alasan_lain" placeholder="Alasan lain…" class="border rounded px-2 py-1 text-xs flex-1">
                                                <button class="bg-orange-500 text-white px-2 rounded text-xs">OK</button>
                                            </div>
                                        </form>
                                    </details>
                                    @if ($t->mulai_at)<span class="text-[10px] text-gray-400 ml-auto">mulai {{ $t->mulai_at->format('H:i') }}</span>@endif
                                @elseif ($status === 'pending')
                                    <form method="POST" action="{{ route('tasks.meja.mulai', $t->id) }}">@csrf
                                        <button class="bg-blue-600 text-white px-2 py-1 rounded text-xs font-semibold">▶ Lanjutkan</button>
                                    </form>
                                @else
                                    <span class="text-[11px] text-green-700">✓ {{ $t->done_at?->format('H:i') }}</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-xs text-gray-400 py-6">Kosong</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</div>

<script>
// Tombol aksi bertanda data-salin: salin nomor pesanan ke clipboard dulu (untuk ditempel di
// kolom cari Seller Centre), lalu biarkan tautannya terbuka seperti biasa.
document.querySelectorAll('.meja-aksi[data-salin]').forEach(function (a) {
    a.addEventListener('click', function (e) {
        var teks = a.getAttribute('data-salin');
        if (navigator.clipboard) navigator.clipboard.writeText(teks).catch(function () {});
        if (window.toast) window.toast('Nomor pesanan ' + teks + ' disalin');
        if (a.getAttribute('href') === '#') e.preventDefault();
    });
});
</script>
@endsection
