@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

@php
    [$statusLabel, $statusCls] = $req->statusBadge();

    $bahaya = collect($review['warnings'])->where('level', 'danger');
    $ingat  = collect($review['warnings'])->where('level', 'warn');

    $statusAbsen = [
        'hadir' => ['Hadir', 'bg-green-100 text-green-700'],
        'terlambat' => ['Terlambat', 'bg-amber-100 text-amber-700'],
        'pulang_awal' => ['Pulang Awal', 'bg-amber-100 text-amber-700'],
        'setengah_hari' => ['Setengah Hari', 'bg-amber-100 text-amber-700'],
        'tidak_hadir' => ['Tidak Hadir', 'bg-red-100 text-red-700'],
        'libur' => ['Libur', 'bg-gray-100 text-gray-600'],
        'lembur' => ['Lembur', 'bg-indigo-100 text-indigo-700'],
        'sakit' => ['Sakit', 'bg-blue-100 text-blue-700'],
        'cuti' => ['Cuti', 'bg-blue-100 text-blue-700'],
    ];
@endphp

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Tinjau Pengajuan Izin</h1>
        <p class="text-xs text-gray-500 mt-0.5">
            Periksa dulu kenyataan hari itu sebelum memutuskan. Kalau tanggalnya salah, tolak dan minta ajukan ulang.
        </p>
    </div>
    <a href="{{ route('sdm.pengajuan-izin.index', ['status' => $req->status, 'highlight' => $req->id]) }}"
       class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded text-sm">← Kembali</a>
</div>

{{-- Peringatan dulu, sebelum apa pun. Kalau ada yang janggal, itu yang pertama dilihat. --}}
@if($bahaya->isNotEmpty())
    <div class="bg-red-50 border border-red-300 rounded p-3 mb-3">
        <div class="text-sm font-bold text-red-800 mb-1">⚠ Perlu diperiksa sebelum disetujui</div>
        <ul class="list-disc list-inside text-xs text-red-800 space-y-1">
            @foreach($bahaya as $w)<li>{{ $w['text'] }}</li>@endforeach
        </ul>
    </div>
@endif

@if($ingat->isNotEmpty())
    <div class="bg-amber-50 border border-amber-200 rounded p-3 mb-3">
        <div class="text-sm font-bold text-amber-800 mb-1">Catatan</div>
        <ul class="list-disc list-inside text-xs text-amber-800 space-y-1">
            @foreach($ingat as $w)<li>{{ $w['text'] }}</li>@endforeach
        </ul>
    </div>
@endif

@if($bahaya->isEmpty() && $ingat->isEmpty())
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded p-3 mb-3 text-xs">
        Tidak ada yang janggal: tanggalnya hari kerja, dan catatan absensinya cocok dengan pengajuan.
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

    {{-- Isi pengajuan --}}
    <div class="bg-white rounded shadow p-4">
        <div class="text-xs font-bold text-gray-400 uppercase mb-3">Isi Pengajuan</div>

        <dl class="space-y-2.5 text-sm">
            <div>
                <dt class="text-xs text-gray-400">Karyawan</dt>
                <dd class="font-semibold text-gray-800">{{ $req->karyawan->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400">Jenis</dt>
                <dd class="font-semibold text-gray-800">{{ $req->typeLabel() }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400">Tanggal</dt>
                <dd class="text-gray-800">
                    {{ $req->tanggal->translatedFormat('l, d M Y') }}
                    @if($req->tanggal_akhir && !$req->tanggal_akhir->isSameDay($req->tanggal))
                        <br>s/d {{ $req->tanggal_akhir->translatedFormat('l, d M Y') }}
                    @endif
                </dd>
            </div>
            @if($req->paired_date)
                <div>
                    <dt class="text-xs text-gray-400">Tanggal pengganti</dt>
                    <dd class="text-gray-800">{{ $req->paired_date->translatedFormat('l, d M Y') }}</dd>
                </div>
            @endif
            @if($req->sesi || $req->paired_sesi)
                <div>
                    <dt class="text-xs text-gray-400">Sesi</dt>
                    <dd class="text-gray-800">{{ $req->sesi }} ⇄ {{ $req->paired_sesi }}</dd>
                </div>
            @endif
            @if($req->jam_masuk_override || $req->jam_pulang_override)
                <div>
                    <dt class="text-xs text-gray-400">Jam pengganti</dt>
                    <dd class="text-gray-800">{{ $req->jam_masuk_override ?: '—' }} s/d {{ $req->jam_pulang_override ?: '—' }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-xs text-gray-400">Alasan karyawan</dt>
                <dd class="text-gray-800 whitespace-pre-line bg-gray-50 border border-gray-100 rounded p-2 mt-1">{{ $req->alasan ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400">Diajukan</dt>
                <dd class="text-gray-600 text-xs">{{ $req->created_at->translatedFormat('l, d M Y H:i') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400">Status</dt>
                <dd><span class="text-xs font-bold px-2 py-0.5 rounded-full {{ $statusCls }}">{{ $statusLabel }}</span></dd>
            </div>
            @if($req->reviewed_by)
                <div>
                    <dt class="text-xs text-gray-400">Ditinjau</dt>
                    <dd class="text-gray-600 text-xs">
                        {{ $req->reviewed_by }}{{ $req->reviewed_at ? ' · ' . $req->reviewed_at->translatedFormat('d M Y H:i') : '' }}
                        @if($req->review_notes)<br><span class="text-gray-500">“{{ $req->review_notes }}”</span>@endif
                    </dd>
                </div>
            @endif
            @if($review['sisa_cuti'] !== null)
                <div>
                    <dt class="text-xs text-gray-400">Sisa cuti {{ $req->tanggal->year }}</dt>
                    <dd class="font-semibold text-gray-800">{{ $review['sisa_cuti'] }} hari</dd>
                </div>
            @endif
            @if($review['periode'])
                <div>
                    <dt class="text-xs text-gray-400">Periode gaji</dt>
                    <dd class="text-gray-800 text-xs">
                        {{ $review['periode']['label'] }} —
                        {{ $review['periode']['final'] ? 'slip SUDAH final' : ($review['periode']['slip'] ? 'slip ada, belum final' : 'slip belum dibuat') }}
                    </dd>
                </div>
            @endif
        </dl>

        @if($req->lampiran_path)
            <div class="mt-3 pt-3 border-t border-gray-100">
                <div class="text-xs text-gray-400 mb-1">Bukti foto</div>
                <a href="{{ \Illuminate\Support\Facades\Storage::url($req->lampiran_path) }}" target="_blank">
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($req->lampiran_path) }}"
                         alt="Bukti" class="rounded border border-gray-200 max-h-56 object-contain">
                </a>
            </div>
        @endif
    </div>

    {{-- Kenyataan hari itu --}}
    <div class="bg-white rounded shadow p-4 lg:col-span-2">
        <div class="text-xs font-bold text-gray-400 uppercase mb-1">Kenyataan di Absensi</div>
        <p class="text-[11px] text-gray-500 mb-3">
            Scan diambil apa adanya dari mesin fingerprint, belum diolah. Kalau ada scan di hari yang
            diajukan izin, kemungkinan besar tanggalnya salah pilih.
        </p>

        @php $semua = $review['paired'] ? array_merge($review['dates'], [$review['paired'] + ['pengganti' => true]]) : $review['dates']; @endphp

        <div class="space-y-3">
            @foreach($semua as $day)
                <div class="border rounded p-3 {{ !empty($day['pengganti']) ? 'border-indigo-200 bg-indigo-50/40' : 'border-gray-200' }}">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <div class="font-semibold text-gray-800 text-sm">
                            {{ $day['date']->translatedFormat('l, d M Y') }}
                            @if(!empty($day['pengganti']))
                                <span class="text-[10px] font-bold bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded ml-1">TANGGAL PENGGANTI</span>
                            @endif
                            @if(!($day['applied'] ?? true))
                                <span class="text-[10px] font-bold bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded ml-1"
                                      title="Persetujuan melewati Minggu & tanggal merah">TIDAK IKUT DITERAPKAN</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 text-[11px]">
                            @if($day['holiday'])
                                <span class="bg-rose-100 text-rose-700 font-semibold px-2 py-0.5 rounded-full">{{ $day['holiday'] }}</span>
                            @endif
                            @if($day['is_off'])
                                <span class="bg-gray-100 text-gray-600 font-semibold px-2 py-0.5 rounded-full">Jadwal libur</span>
                            @else
                                <span class="text-gray-500">Jadwal {{ substr($day['jam_masuk'] ?? '-', 0, 5) }}–{{ substr($day['jam_pulang'] ?? '-', 0, 5) }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                        <div>
                            <div class="text-gray-400 font-semibold mb-1">Scan fingerprint ({{ count($day['scans']) }})</div>
                            @if(empty($day['scans']))
                                <div class="text-gray-400 italic">Tidak ada scan sama sekali.</div>
                            @else
                                <div class="flex flex-wrap gap-1">
                                    @foreach($day['scans'] as $s)
                                        <span class="bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5 font-mono">{{ $s['jam'] }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div>
                            <div class="text-gray-400 font-semibold mb-1">Baris absensi</div>
                            @if(!$day['attendance'])
                                <div class="text-gray-400 italic">Belum ada baris absensi untuk tanggal ini.</div>
                            @else
                                @php [$sl, $scls] = $statusAbsen[$day['attendance']['status']] ?? [$day['attendance']['status'] ?? '—', 'bg-gray-100 text-gray-600']; @endphp
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[11px] font-bold px-2 py-0.5 rounded-full {{ $scls }}">{{ $sl }}</span>
                                    <span class="text-gray-600 font-mono">
                                        {{ substr($day['attendance']['on_work1'] ?? '—', 0, 5) }} – {{ substr($day['attendance']['off_work1'] ?? '—', 0, 5) }}
                                    </span>
                                    @if($day['attendance']['late_minutes'] > 0)
                                        <span class="text-amber-700">telat {{ $day['attendance']['late_minutes'] }} mnt</span>
                                    @endif
                                    @if($day['attendance']['edited'])
                                        <span class="text-[10px] bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded">diedit manual</span>
                                    @endif
                                </div>
                                @if($day['attendance']['remark'])
                                    <div class="text-gray-500 mt-1">{{ $day['attendance']['remark'] }}</div>
                                @endif
                            @endif
                        </div>
                    </div>

                    @if(!empty($day['overrides']))
                        <div class="mt-2 pt-2 border-t border-gray-100 text-xs">
                            <span class="text-gray-400 font-semibold">Catatan izin yang sudah ada:</span>
                            @foreach($day['overrides'] as $o)
                                <span class="inline-block bg-amber-50 border border-amber-200 text-amber-800 rounded px-1.5 py-0.5 ml-1">
                                    {{ $o['type'] }}@if($o['notes']) — {{ $o['notes'] }}@endif
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Keputusan --}}
        @if($req->isPending())
            <div class="mt-4 pt-4 border-t border-gray-200">
                <div class="text-xs font-bold text-gray-400 uppercase mb-2">Keputusan</div>
                <div class="flex flex-wrap gap-2 items-start">
                    <form method="POST" action="{{ route('sdm.pengajuan-izin.approve', $req->id) }}"
                          onsubmit="return confirm('Setujui pengajuan ini? Izin/override akan dibuat.');">
                        @csrf
                        <button class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded text-sm font-semibold">
                            Setujui
                        </button>
                    </form>

                    <form method="POST" action="{{ route('sdm.pengajuan-izin.reject', $req->id) }}" class="flex flex-wrap gap-2 items-start">
                        @csrf
                        <div>
                            <input type="text" name="review_notes" maxlength="255"
                                   value="{{ $bahaya->isNotEmpty() ? 'Tanggal tidak sesuai catatan absensi — mohon ajukan ulang dengan tanggal yang benar.' : '' }}"
                                   placeholder="Alasan tolak — dikirim ke HP karyawan"
                                   class="border border-gray-300 rounded px-2 py-2 text-sm w-96">
                            <p class="text-[11px] text-gray-400 mt-1">Catatan ini muncul di notifikasi karyawan, jadi tulis apa yang harus dia perbaiki.</p>
                        </div>
                        <button class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm font-semibold">
                            Tolak &amp; minta ajukan ulang
                        </button>
                    </form>
                </div>
            </div>
        @elseif($req->isApproved())
            <div class="mt-4 pt-4 border-t border-gray-200">
                <form method="POST" action="{{ route('sdm.pengajuan-izin.cancel', $req->id) }}"
                      onsubmit="return confirm('Batalkan approval & revert efeknya?');">
                    @csrf
                    <button class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded text-sm font-semibold">
                        Batalkan Persetujuan
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
