@extends('layouts.erp')

@section('content')
@php
    $judulKolom = [
        'open'    => ['Daftar Pekerjaan', 'bg-slate-500', 'Urut skor — yang teratas dikerjakan dulu. Selesai sendiri saat dokumennya dibuat di ERP.'],
        'pending' => ['Pekerjaan Tertunda', 'bg-orange-500', 'Menunggu pelanggan/barang/keputusan'],
    ];
    $peranLabel = \App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran::PERAN;
    $alasanCepat = ['Menunggu balasan pelanggan', 'Menunggu barang datang', 'Menunggu keputusan owner'];
@endphp
<div class="max-w-[1600px] mx-auto">
    <div class="flex items-start justify-between gap-3 flex-wrap mb-4">
        <div>
            <h2 class="text-xl font-bold text-gray-800">Meja Kerja</h2>
            <p class="text-sm text-gray-500">
                @if ($lihat === 'saya')
                    Task yang ditugaskan Manajer Admin kepada Anda{{ $peranSaya ? ' + yang belum berpengerja di meja ' . collect($peranSaya)->map(fn ($p) => $peranLabel[$p] ?? $p)->implode(', ') : '' }}.
                @elseif ($lihat === 'semua')
                    Semua meja peran.
                @elseif ($lihat === 'owner')
                    Semua task Meja Owner + task yang ditugaskan kepada Anda.
                @else
                    Semua task Meja {{ $peranLabel[$lihat] }} — pengerjanya ditentukan Manajer Admin.
                @endif
                Task otomatis selesai saat pekerjaannya tuntas di ERP.
            </p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            @if ($bolehLapor)
                <details class="relative">
                    <summary class="list-none cursor-pointer bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded text-sm font-semibold">Lapor ke Owner</summary>
                    <form method="POST" action="{{ route('tasks.meja.lapor.store') }}" enctype="multipart/form-data" class="absolute right-0 z-20 mt-1 w-96 max-w-[90vw] bg-white border rounded shadow-lg p-3 space-y-2 text-sm">@csrf
                        <div class="text-xs text-gray-500">Langsung sampai ke owner, juga lewat Telegram. Balasannya muncul di lonceng notifikasi Anda.</div>
                        <textarea name="isi" rows="4" required maxlength="2000" placeholder="Mis. saldo ShopeePay belum diisi, transaksi belum tercatat..." class="w-full border rounded px-2 py-1.5">{{ old('isi') }}</textarea>
                        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" class="w-full text-xs">
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" name="penting" value="1" @checked(old('penting'))> Penting
                        </label>
                        <button class="w-full bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded font-semibold">Kirim laporan</button>
                    </form>
                </details>
            @endif
            @if ($bolehSemua || $peranSaya)
                <a href="{{ route('tasks.meja.print-rusak.index') }}" class="border border-red-300 text-red-700 hover:bg-red-50 px-3 py-2 rounded text-sm font-medium">Lapor print rusak</a>
            @endif
            @if ($bolehSemua || $peranSaya)
                <form method="POST" action="{{ route('tasks.meja.sinkron') }}">@csrf
                    <button class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded text-sm font-medium">Sinkron sekarang</button>
                </form>
            @endif
        </div>
    </div>

    @php
        // Tab pertama = meja utama: Meja Owner bagi owner, Meja saya bagi lainnya.
        $tab = [$mejaUtama => $mejaUtama === 'owner' ? 'Meja Owner' : 'Meja saya'] + collect($tabPeran)->map(fn ($l) => 'Meja ' . $l)->all() + ($bolehSemua ? ['semua' => 'Semua meja'] : []);
    @endphp
    <div class="flex flex-wrap gap-1 border-b border-gray-200 mb-4">
        @foreach ($tab as $k => $l)
            <a href="{{ route('tasks.meja.index', ['lihat' => $k]) }}"
               class="px-3 py-2 text-sm -mb-px border-b-2 {{ $lihat === $k ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                {{ $l }}
                @if (($jumlahAntre[$k] ?? 0) > 0)<span class="ml-1 text-[11px] bg-slate-200 text-slate-700 rounded-full px-1.5">{{ $jumlahAntre[$k] }}</span>@endif
            </a>
        @endforeach
    </div>

    @if ($bayanganPerMeja->isNotEmpty())
        {{-- Pratinjau skill mode Bayangan: hanya owner yang melihat, tidak bisa dikerjakan. --}}
        <div class="mb-3 flex flex-wrap items-center gap-2 text-xs bg-violet-50 border border-dashed border-violet-300 text-violet-800 rounded px-3 py-2">
            <span class="font-semibold">👻 Pratinjau Bayangan</span>
            <span>— kartu dari skill yang masih mode Bayangan (belum dibagikan ke admin, baca saja):</span>
            @foreach ($bayanganPerMeja as $p => $n)
                <span class="bg-white border border-violet-200 rounded-full px-2 py-0.5">{{ $peranLabel[$p] ?? $p }}: <b>{{ $n }}</b></span>
            @endforeach
            <a href="{{ route('tasks.meja.index', ['lihat' => $lihat, 'bayangan' => $tampilBayangan ? '0' : '1']) }}"
               class="ml-auto underline">{{ $tampilBayangan ? 'Sembunyikan' : 'Tampilkan' }}</a>
        </div>
    @endif

    @if ($jumlahJenis->count() > 1 || $saringJenis !== '')
        {{-- Saringan jenis pekerjaan --}}
        <div class="mb-3 flex flex-wrap items-center gap-1.5 text-xs">
            @php $parameter = fn ($jenis) => array_filter(['lihat' => $lihat, 'bayangan' => request('bayangan'), 'jenis' => $jenis], fn ($v) => $v !== null && $v !== ''); @endphp
            <a href="{{ route('tasks.meja.index', $parameter('')) }}" class="px-2 py-1 rounded font-semibold {{ $saringJenis === '' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">Semua</a>
            @foreach ($jumlahJenis as $jenis => $n)
                <a href="{{ route('tasks.meja.index', $parameter($jenis)) }}" class="px-2 py-1 rounded font-semibold {{ \App\Modules\Tasks\MejaKerja\MejaKerjaService::warnaJenis($jenis) }} {{ $saringJenis === $jenis ? 'ring-2 ring-gray-800' : 'hover:opacity-80' }}">{{ $jenis }} {{ $n }}</a>
            @endforeach
        </div>
    @endif

    <div class="grid gap-3 md:grid-cols-2 items-start">
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
                            $bayang = (bool) $t->getAttribute('bayangan');
                            $lewat = $t->tenggat_at && $t->tenggat_at->isPast();
                            $pakaiMulai = \App\Modules\Tasks\MejaKerja\MejaKerjaService::butuhMulai($skill);
                        @endphp
                        <div class="rounded-md p-3 text-sm {{ $bayang ? 'bg-violet-50/60 border-2 border-dashed border-violet-300' : 'bg-white border ' . ($lewat ? 'border-red-300' : 'border-gray-200') }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-semibold text-gray-800 leading-snug">
                                    <span class="px-1.5 py-0.5 rounded text-xs mr-1 {{ \App\Modules\Tasks\MejaKerja\MejaKerjaService::warnaJenis($jenisKartu[$t->id]['jenis']) }}">{{ $jenisKartu[$t->id]['jenis'] }}</span>{{ $jenisKartu[$t->id]['isi'] }}
                                </div>
                                @if ($status === 'open')<span class="text-[10px] text-gray-400 whitespace-nowrap">skor {{ $skor[$t->id] }}</span>@endif
                            </div>
                            <div class="flex flex-wrap gap-1 mt-1 text-[10px]">
                                @if ($bayang)<span class="px-1.5 py-0.5 rounded bg-violet-600 text-white font-bold" title="Skill masih mode Bayangan — kartu ini belum dibuat untuk admin">👻 BAYANGAN</span>@endif
                                @if ($skill)<span class="px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 font-semibold">{{ $skill->label() }}</span>@endif
                                @if ($t->peran)<span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">{{ $peranLabel[$t->peran] ?? $t->peran }}</span>@endif
                                <span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">{{ $t->assignee?->name ?? 'Belum ada pengerja' }}</span>
                                @if ($t->status === 'in_progress')<span class="px-1.5 py-0.5 rounded bg-blue-600 text-white font-semibold">Sedang dikerjakan · {{ $t->mulai_at?->format('H:i') }}</span>@endif
                                @if ($t->tenggat_at)
                                    @php
                                        // Sisa waktu, bukan jam tenggat — admin langsung tahu seberapa mendesak.
                                        $sisaMnt = (int) floor(now()->diffInMinutes($t->tenggat_at, false));
                                        $sisaTeks = match (true) {
                                            $lewat              => 'Lewat ' . $t->tenggat_at->diffForHumans(null, true),
                                            $sisaMnt < 60       => "Sisa {$sisaMnt} mnt",
                                            $sisaMnt < 48 * 60  => 'Sisa ' . intdiv($sisaMnt, 60) . ' jam ' . ($sisaMnt % 60) . ' mnt',
                                            default             => 'Sisa ' . intdiv($sisaMnt, 1440) . ' hari ' . intdiv($sisaMnt % 1440, 60) . ' jam',
                                        };
                                    @endphp
                                    @if (!empty($t->meta['batas_kirim']))
                                        {{-- Batas kirim Pemrosesan Pesanan — warnanya sama dengan kartu Pemrosesan: < 12 jam kuning, lewat merah. --}}
                                        <span class="px-1.5 py-0.5 rounded {{ $lewat ? 'bg-red-600 text-white font-bold' : ($sisaMnt <= 720 ? 'bg-amber-200 text-amber-900 font-bold' : 'bg-gray-100 text-gray-600') }}"
                                              title="Batas kirim (sama dengan Pemrosesan Pesanan) — {{ $t->tenggat_at->format('d/m/Y H:i') }}">
                                            ⏱ Batas kirim {{ $t->tenggat_at->format('d/m H:i') }} · {{ $lewat ? 'lewat ' . $t->tenggat_at->diffForHumans(null, true) : lcfirst($sisaTeks) }}
                                        </span>
                                    @elseif ($t->tenggatDariLuar())
                                        {{-- Batas sungguhan (Shopee / jatuh tempo) — merah, hitung mundur. --}}
                                        <span class="px-1.5 py-0.5 rounded bg-red-600 text-white font-bold"
                                              title="Batas {{ $t->tenggat_at->format('d/m/Y H:i') }}">
                                            ⏱ {{ $sisaTeks }}
                                        </span>
                                    @else
                                        {{-- Target kerja bawaan skill — abu-abu; merah hanya bila terlewat. --}}
                                        <span class="px-1.5 py-0.5 rounded {{ $lewat ? 'bg-red-100 text-red-700 font-bold' : 'bg-gray-100 text-gray-500' }}"
                                              title="Target kerja internal (bawaan skill), bukan batas marketplace — {{ $t->tenggat_at->format('d/m/Y H:i') }}">
                                            Target: {{ $lewat ? 'lewat ' . $t->tenggat_at->diffForHumans(null, true) : lcfirst($sisaTeks) }}
                                        </span>
                                    @endif
                                @endif
                            </div>
                            @if ($status === 'pending' && $t->pending_alasan)
                                <div class="mt-2 text-xs text-orange-700 bg-orange-50 rounded px-2 py-1">⏸ {{ $t->pending_alasan }} · sejak {{ $t->pending_sejak?->format('d/m H:i') }}</div>
                            @endif
                            @if (!empty(($resiRetur ?? [])[$t->id]))
                                <div class="mt-2">@include('erp.sales.returns._resi_shopee', ['kirimBalik' => $resiRetur[$t->id]])</div>
                            @endif
                            @php
                                // Catatan pembeli & Instruksi diambil langsung dari SO (selalu terbaru); salinan
                                // "Catatan pembeli:" di deskripsi kartu lama dibuang supaya tidak dobel.
                                $cso = ($catatanSo ?? [])[$t->id] ?? null;
                                $rincian = $cso ? trim(preg_replace('/\n?Catatan pembeli:.*$/su', '', (string) $t->description)) : $t->description;
                            @endphp
                            @if ($rincian || $cso)
                                <details class="mt-2 text-xs text-gray-600" @if ($cso) open @endif><summary class="cursor-pointer text-gray-400">Rincian</summary>
                                    @if ($rincian)<div class="whitespace-pre-line mt-1">{{ $rincian }}</div>@endif
                                    @if ($cso['catatan'] ?? null)
                                        <div class="mt-1"><span class="text-gray-400 font-semibold">💬 Catatan pembeli</span>
                                            <div class="whitespace-pre-line break-words text-gray-800">{{ $cso['catatan'] }}</div></div>
                                    @endif
                                    @if ($cso['instruksi'] ?? null)
                                        <div class="mt-1"><span class="text-gray-400 font-semibold">🏷 Instruksi</span>
                                            <div class="whitespace-pre-line break-words text-gray-800 font-semibold">{{ $cso['instruksi'] }}</div></div>
                                    @endif
                                </details>
                            @endif

                            {{-- Semua tombol kartu satu baris; turun ke baris berikutnya hanya bila tak muat. --}}
                            <div class="flex flex-wrap items-center gap-1 mt-2 pt-2 border-t border-gray-100">
                            @if ($skill)
                                    @foreach ($skill->aksi($t) as $a)
                                        @php
                                            $warnaAksi = match ($a['warna'] ?? (!empty($a['utama']) ? 'oren' : null)) {
                                                'oren'  => 'bg-orange-500 text-white hover:bg-orange-600',
                                                'hijau' => 'bg-green-600 text-white hover:bg-green-700',
                                                default => 'border border-gray-300 text-gray-700 hover:bg-gray-50',
                                            };
                                        @endphp
                                        @if (!empty($a['kirim']))
                                            {{-- Pesan ke pelanggan: teksnya ditunjukkan & bisa diubah dulu, baru dikirim. --}}
                                            <details class="relative">
                                                <summary class="list-none cursor-pointer px-2 py-1 rounded text-xs font-semibold {{ $warnaAksi }}">{{ $a['label'] }}</summary>
                                                <form method="POST" action="{{ $a['kirim'] }}" @empty($a['tab_ini']) target="_blank" @endempty class="absolute z-10 mt-1 w-80 bg-white border rounded shadow p-2 space-y-1">@csrf
                                                    <div class="text-[11px] text-gray-500">{{ $a['keterangan'] ?? 'Dikirim ke pembeli: kartu pesanan, lalu pesan ini.' }}</div>
                                                    <textarea name="teks" rows="4" maxlength="1000" class="w-full border rounded px-2 py-1 text-xs">{{ $a['teks'] ?? '' }}</textarea>
                                                    <button class="w-full px-2 py-1 rounded text-xs font-semibold {{ $warnaAksi }}">{{ $a['tombol'] ?? 'Kirim & buka chat' }}</button>
                                                </form>
                                            </details>
                                        @else
                                        @php
                                            // Chat CRM dibuka dari kartu → ?meja=<kartu>: pemegang kartu otomatis jadi pemilik chat.
                                            $urlAksi = $a['url'] ?? null;
                                            if ($urlAksi && !$bayang && preg_match('#/erp/crm/\d+$#', parse_url($urlAksi, PHP_URL_PATH) ?? '')) {
                                                $urlAksi .= (str_contains($urlAksi, '?') ? '&' : '?') . 'meja=' . $t->id;
                                            }
                                        @endphp
                                        <a href="{{ $urlAksi ?? '#' }}" @if (!empty($urlAksi)) target="_blank" rel="noopener" @endif
                                           @if (!empty($a['salin'])) data-salin="{{ $a['salin'] }}" @endif
                                           class="meja-aksi px-2 py-1 rounded text-xs font-semibold {{ $warnaAksi }}">{{ $a['label'] }}</a>
                                        @endif
                                    @endforeach
                            @endif

                            @unless ($bayang)
                                @if ($skill && method_exists($skill, 'formKartu'))
                                    @include('erp.tasks.meja._form-kartu', ['form' => $skill->formKartu($t)])
                                @endif
                                @if ($skill instanceof \App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban && ($jawaban = $skill->jawaban($t)))
                                    <form method="POST" action="{{ route('tasks.meja.jawab', $t->id) }}" class="contents">@csrf
                                        @foreach ($jawaban as $j)
                                            <button name="jawaban" value="{{ $j['kunci'] }}"
                                                    class="px-2 py-1 rounded text-xs font-semibold {{ !empty($j['utama']) ? 'bg-green-600 text-white' : 'border border-green-300 text-green-800 hover:bg-green-50' }}">{{ $j['label'] }}</button>
                                        @endforeach
                                    </form>
                                @endif
                                @if ($t->status === 'open' && $pakaiMulai)
                                    <form method="POST" action="{{ route('tasks.meja.mulai', $t->id) }}">@csrf
                                        <button class="bg-blue-600 text-white px-2 py-1 rounded text-xs font-semibold">▶ Mulai</button>
                                    </form>
                                @endif
                                @if ($status === 'pending' && !method_exists($skill ?? new stdClass, 'kendaliSendiri'))
                                    <form method="POST" action="{{ route('tasks.meja.lanjutkan', $t->id) }}">@csrf
                                        <button class="bg-blue-600 text-white px-2 py-1 rounded text-xs font-semibold">▶ Lanjutkan</button>
                                    </form>
                                @endif
                                @if (\App\Modules\Tasks\MejaKerja\MejaKerjaService::bolehSelesaiManual($skill, $t) && !($pakaiMulai && $t->status === 'open'))
                                    <form method="POST" action="{{ route('tasks.meja.selesai', $t->id) }}">@csrf
                                        <button class="bg-green-600 text-white px-2 py-1 rounded text-xs font-semibold">✓ Selesai</button>
                                    </form>
                                @endif
                                @if ($status === 'open' && !method_exists($skill ?? new stdClass, 'kendaliSendiri'))
                                    <details class="relative">
                                        <summary class="list-none cursor-pointer border border-orange-300 text-orange-700 px-2 py-1 rounded text-xs font-semibold">⏸ Tunda</summary>
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
                                @endif
                            @endunless
                            </div>
                            @if ($bayang)
                                <div class="mt-2 text-[11px] text-violet-700">Baca saja — akan muncul di meja ini bila skillnya diubah ke Otomatis.</div>
                            @endif
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
