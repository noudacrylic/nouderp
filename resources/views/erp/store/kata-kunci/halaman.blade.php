@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-1 gap-2 flex-wrap">
    <div class="flex items-center gap-3">
        <h1 class="text-lg font-semibold">Tampil tapi tak diklik</h1>
        <a href="{{ route('store.kata-kunci.index') }}" class="text-sm text-blue-700">← Bank kata kunci</a>
    </div>
    @if ($tersambung)
        <form method="POST" action="{{ route('store.kata-kunci.halaman.susun') }}">@csrf
            <button class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm disabled:opacity-40" @disabled($antre)>{{ $antre ? '⏳ Sedang disusun…' : '🔄 Susun ulang laporan' }}</button>
        </form>
    @endif
</div>
<p class="text-xs text-gray-500 mb-3">
    Halaman noudakrilik.com yang sering muncul di Google (posisi ≤15, ≥30 tayang dalam 28 hari) tapi jarang diklik (&lt;3%).
    AI mengusulkan judul & deskripsi baru yang memuat kata yang benar-benar dicari. Halaman produk bisa langsung dipakai;
    halaman lain (beranda/kategori) disalin manual. Otomatis disusun ulang tiap Senin pagi.
    @if ($terakhir)
        <br>Terakhir {{ \Illuminate\Support\Carbon::parse($terakhir['waktu'])->translatedFormat('d M H:i') }}:
        {{ isset($terakhir['galat']) ? 'gagal — ' . $terakhir['galat'] : $terakhir['halaman'] . ' halaman, ' . $terakhir['diusulkan'] . ' diberi usulan' }}.
    @endif
</p>

@unless ($tersambung)
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded p-3 text-sm">Sambungkan Google Search Console dulu di halaman Bank kata kunci.</div>
@endunless

<div class="space-y-3">
    @forelse ($saran as $s)
        <div class="bg-white rounded shadow p-3 text-sm {{ $s->status === 'diabaikan' ? 'opacity-50' : '' }}">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div class="min-w-0">
                    <a href="{{ $s->url }}" target="_blank" rel="noopener" class="font-semibold text-blue-700 break-all">{{ preg_replace('#^https?://(www\.)?#', '', $s->url) }}</a>
                    <span class="ml-1 text-[11px] bg-gray-100 rounded px-1.5">{{ $s->jenis }}</span>
                    @if ($s->status === 'dipakai')<span class="ml-1 text-[11px] bg-green-100 text-green-800 rounded px-1.5">dipakai {{ $s->dipakai_at?->translatedFormat('d M') }}</span>@endif
                </div>
                <div class="text-xs text-gray-600 shrink-0">
                    <b>{{ number_format($s->tayang, 0, ',', '.') }}</b> tayang · <b>{{ $s->klik }}</b> klik ·
                    CTR <b class="text-red-600">{{ number_format($s->ctr(), 1, ',', '.') }}%</b> · posisi <b>{{ number_format($s->posisi, 1, ',', '.') }}</b>
                </div>
            </div>
            @if ($s->kueri)
                <div class="mt-1 text-xs text-gray-500">Dicari: {{ collect($s->kueri)->map(fn ($q) => $q['kata'] . ' (' . $q['tayang'] . ')')->implode(' · ') }}</div>
            @endif

            <div class="grid md:grid-cols-2 gap-3 mt-2">
                <div class="text-xs">
                    <div class="text-gray-400 font-semibold mb-0.5">Sekarang tampil di Google</div>
                    <div class="text-blue-800">{{ $s->judul_sekarang ?: '—' }}</div>
                    <div class="text-gray-600">{{ $s->deskripsi_sekarang ?: '—' }}</div>
                </div>
                <form method="POST" action="{{ route('store.kata-kunci.halaman.pakai', $s->id) }}" class="text-xs space-y-1">@csrf
                    <div class="text-gray-400 font-semibold">Usulan AI {{ $s->alasan ? '— ' . $s->alasan : '' }}</div>
                    <input name="judul" value="{{ $s->judul_usulan }}" maxlength="255" class="w-full border rounded px-2 py-1"
                           oninput="this.nextElementSibling.textContent = this.value.length + '/60'">
                    <div class="text-[10px] text-gray-400 text-right">{{ mb_strlen((string) $s->judul_usulan) }}/60</div>
                    <textarea name="deskripsi" rows="2" maxlength="500" class="w-full border rounded px-2 py-1"
                              oninput="this.nextElementSibling.textContent = this.value.length + '/155'">{{ $s->deskripsi_usulan }}</textarea>
                    <div class="text-[10px] text-gray-400 text-right">{{ mb_strlen((string) $s->deskripsi_usulan) }}/155</div>
                    <div class="flex gap-2 justify-end">
                        @if ($s->store_product_id)
                            <button class="bg-green-600 text-white px-3 py-1 rounded font-semibold" @disabled(!$s->judul_usulan)>✓ Pakai di produk</button>
                        @else
                            <span class="text-gray-500 self-center">Halaman {{ $s->jenis }} — salin manual ke pengaturannya.</span>
                        @endif
                        <button form="abaikan-{{ $s->id }}" class="border px-3 py-1 rounded">{{ $s->status === 'diabaikan' ? 'Tampilkan lagi' : 'Abaikan' }}</button>
                    </div>
                </form>
                <form id="abaikan-{{ $s->id }}" method="POST" action="{{ route('store.kata-kunci.halaman.abaikan', $s->id) }}">@csrf</form>
            </div>
        </div>
    @empty
        <div class="bg-white rounded shadow p-6 text-center text-sm text-gray-500">
            Belum ada laporan. {{ $tersambung ? 'Tekan 🔄 Susun ulang laporan.' : '' }}
        </div>
    @endforelse
</div>
@endsection
