@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Periksa Saran AI</h1>
        <p class="text-xs text-gray-500">Koreksi yang disimpan langsung dibaca AI pada saran berikutnya.</p>
    </div>
    <a href="{{ route('crm.agen.index') }}" class="text-sm text-emerald-700 hover:underline">← Agen AI</a>
</div>

<form method="GET" class="filter-auto flex flex-wrap items-end gap-2 mb-3 text-sm">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Pemeriksaan</label>
        <select name="periksa" class="border rounded px-2 py-1.5">
            <option value="belum" @selected($periksa === 'belum')>Belum diperiksa ({{ $jumlah['belum'] }})</option>
            <option value="tepat" @selected($periksa === 'tepat')>Tepat ({{ $jumlah['tepat'] }})</option>
            <option value="salah" @selected($periksa === 'salah')>Salah ({{ $jumlah['salah'] }})</option>
            <option value="semua" @selected($periksa === 'semua')>Semua</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Butir</label>
        <input type="text" name="butir" value="{{ request('butir') }}" placeholder="mis. A1" class="border rounded px-2 py-1.5 w-24">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Dilempar ke</label>
        <select name="lempar" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="tidak" @selected(request('lempar') === 'tidak')>Dijawab AI</option>
            <option value="desain" @selected(request('lempar') === 'desain')>Admin desain</option>
            <option value="admin" @selected(request('lempar') === 'admin')>Admin layanan</option>
            <option value="owner" @selected(request('lempar') === 'owner')>Owner</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Kirim otomatis</label>
        <select name="otomatis" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="terkirim" @selected(request('otomatis') === 'terkirim')>Terkirim AI (luar jam)</option>
            <option value="bayangan" @selected(request('otomatis') === 'bayangan')>Bayangan (akan terkirim)</option>
            <option value="gagal" @selected(request('otomatis') === 'gagal')>Gagal kirim</option>
        </select>
    </div>
    @include('erp._partials.per-page-select')
</form>

@error('koreksi')<div class="mb-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div>@enderror

<div class="space-y-3">
    @forelse($daftar as $s)
        <div class="bg-white rounded shadow p-4 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-2 text-xs text-gray-500">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('crm.inbox.show', $s->conversation_id) }}" class="font-semibold text-emerald-700 hover:underline">{{ $s->conversation?->display_name ?: $s->conversation?->contact_key }}</a>
                    <span>{{ $s->created_at?->format('d/m H:i') }}</span>
                    @if($s->butir)<span class="px-1.5 rounded bg-gray-100">butir {{ $s->butir }}</span>@endif
                    @if($s->lempar_ke)<span class="px-1.5 rounded bg-amber-100 text-amber-800 font-semibold">perlu {{ ['admin' => 'admin layanan', 'desain' => 'admin desain', 'owner' => 'owner'][$s->lempar_ke] ?? $s->lempar_ke }}</span>@endif
                    <span class="px-1.5 rounded bg-gray-100">{{ $s->status }}</span>
                    @if($s->otomatis === 'terkirim')<span class="px-1.5 rounded bg-violet-100 text-violet-800 font-semibold" title="Dikirim sendiri oleh AI di luar jam kerja">🤖 terkirim AI</span>
                    @elseif($s->otomatis === 'bayangan')<span class="px-1.5 rounded bg-violet-50 text-violet-700" title="Mode bayangan: akan terkirim bila mode Otomatis">🤖 bayangan</span>
                    @elseif($s->otomatis === 'gagal')<span class="px-1.5 rounded bg-red-100 text-red-700" title="{{ $s->catatan }}">🤖 gagal kirim</span>@endif
                </div>
                @if($s->tinjauan)
                    <span class="font-semibold {{ $s->tinjauan === 'tepat' ? 'text-emerald-700' : 'text-red-600' }}">
                        {{ $s->tinjauan === 'tepat' ? '✓ Tepat' : '✗ Salah' }}{{ $s->peninjau ? ' · ' . $s->peninjau->name : '' }}
                    </span>
                @endif
            </div>

            @if(($konteks[$s->id] ?? collect())->isNotEmpty())
                <details class="mb-2 text-xs text-gray-600">
                    <summary class="cursor-pointer text-gray-500">Pesan sebelumnya</summary>
                    <div class="mt-1 space-y-0.5">
                        @foreach($konteks[$s->id] as $m)
                            <div><b>{{ $m->direction === 'masuk' ? 'Pembeli' : 'Toko' }}:</b> {{ \Illuminate\Support\Str::limit((string) $m->content ?: '[lampiran]', 200) }}</div>
                        @endforeach
                    </div>
                </details>
            @endif

            <div class="grid md:grid-cols-2 gap-3">
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">Pesan pembeli</div>
                    <div class="whitespace-pre-wrap">{{ $s->pesan?->content ?: ($s->pesan?->attachments?->isNotEmpty() ? '[lampiran]' : '—') }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">Saran AI</div>
                    <div class="whitespace-pre-wrap">{{ $s->teks ?: '(tanpa balasan)' }}</div>
                    @if($s->alasan)<div class="mt-1 text-xs text-purple-600">{{ $s->alasan }}</div>@endif
                    @if($s->teks_terkirim && $s->teks_terkirim !== $s->teks)
                        <div class="mt-2 text-xs text-gray-500">Dikirim admin: <span class="text-gray-800">{{ $s->teks_terkirim }}</span></div>
                    @endif
                </div>
            </div>

            <div class="mt-3 border-t pt-3">
                <form method="POST" action="{{ route('crm.agen.saran.tinjau', $s) }}">
                    @csrf
                    <textarea name="koreksi" rows="2" maxlength="1000" placeholder="Kalau salah: tulis jawaban atau aturan yang benar. Mis. &quot;ukuran lain lempar ke owner&quot;"
                              class="w-full border rounded px-2 py-1.5 text-sm mb-2">{{ $s->koreksi }}</textarea>
                    <div class="flex items-center gap-2">
                        <button name="tinjauan" value="tepat" class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold">✓ Tepat</button>
                        <button name="tinjauan" value="salah" class="px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white text-xs font-semibold">✗ Salah, simpan koreksi</button>
                    </div>
                </form>
            </div>
        </div>
    @empty
        <div class="bg-white rounded shadow p-6 text-center text-sm text-gray-500">Tidak ada saran untuk saringan ini.</div>
    @endforelse
</div>

<div class="mt-3">{{ $daftar->links() }}</div>
@endsection
