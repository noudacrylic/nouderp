@extends('layouts.erp')

@section('content')
@php
    $judul = $percakapan->customer->name ?? $percakapan->display_name ?? $percakapan->contact_key;
    $terbuka = $percakapan->windowIsOpen();
@endphp

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">{{ $judul }}</h1>
        <div class="text-xs text-gray-500">
            {{ $percakapan->contact_key }}
            @if($percakapan->customer_id)
                &middot; <a href="{{ url('/erp/master/customers/' . $percakapan->customer_id . '/edit') }}" class="text-blue-600 hover:underline">lihat pelanggan</a>
            @else
                &middot; <span title="Belum tertaut pelanggan mana pun di ERP">lead</span>
            @endif
        </div>
    </div>
    <a href="{{ list_url('crm.inbox.index') }}" class="border border-gray-300 hover:bg-gray-50 px-3 py-2 rounded text-sm">← Inbox</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    {{-- ------------------------------------------------------------ percakapan --}}
    <div class="lg:col-span-2 space-y-3">
        <div class="bg-white rounded shadow p-3 max-h-[32rem] overflow-y-auto space-y-3">
            @forelse($pesan as $m)
                @php $masuk = $m->isInbound(); @endphp
                <div class="flex {{ $masuk ? 'justify-start' : 'justify-end' }}">
                    <div class="max-w-[80%] rounded px-3 py-2 text-sm {{ $masuk ? 'bg-gray-100' : 'bg-blue-50' }}">
                        @if($m->content)
                            <div class="whitespace-pre-wrap">{{ $m->content }}</div>
                        @endif

                        @foreach($m->attachments as $l)
                            <div class="mt-2">
                                @if($l->sudahDisapu())
                                    <div class="text-xs text-gray-500 italic">
                                        Lampiran dihapus otomatis {{ $l->purged_at->translatedFormat('d M Y') }} (lewat masa simpan).
                                    </div>
                                @elseif(! $l->tersimpanAman())
                                    <div class="text-xs text-amber-700">
                                        Lampiran belum tersimpan{{ $l->download_error ? ': ' . $l->download_error : ' — menunggu diunduh.' }}
                                    </div>
                                @elseif($l->isGambar())
                                    <a href="{{ route('crm.inbox.lampiran', $l->id) }}" target="_blank">
                                        <img src="{{ route('crm.inbox.lampiran', $l->id) }}" alt="{{ $l->original_name }}"
                                             class="rounded border max-h-56">
                                    </a>
                                @else
                                    <a href="{{ route('crm.inbox.lampiran', $l->id) }}"
                                       class="inline-block border border-gray-300 rounded px-2 py-1 text-xs hover:bg-gray-50">
                                        ⬇ {{ $l->original_name ?: 'Unduh lampiran' }}
                                    </a>
                                @endif
                            </div>
                        @endforeach

                        <div class="mt-1 text-[11px] text-gray-500 flex items-center gap-2">
                            <span>{{ $m->sent_at?->translatedFormat('d M Y H:i') }}</span>
                            @if($m->dibalasDariHp())
                                {{-- Kebocoran triase: dibalas langsung dari HP, jadi tidak lewat
                                     penugasan, catatan, maupun antrean di layar ini. --}}
                                <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800" title="Dibalas langsung dari aplikasi WhatsApp, bukan dari ERP">dari HP</span>
                            @endif
                            @if($m->status === 'failed')
                                <span class="px-1.5 py-0.5 rounded bg-red-100 text-red-700" title="{{ $m->error }}">gagal</span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-center text-gray-500 py-6">Belum ada pesan.</p>
            @endforelse
        </div>

        {{-- ---------------------------------------------------------- balasan --}}
        <div class="bg-white rounded shadow p-3">
            @if($terbuka)
                @if($dryRun)
                    <div class="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Mode aman menyala — balasan dicatat di thread, tidak dikirim ke pelanggan.
                    </div>
                @endif
                <form method="POST" action="{{ route('crm.inbox.balas', $percakapan->id) }}">
                    @csrf
                    <textarea name="teks" rows="3" required maxlength="4000"
                              class="border rounded w-full px-3 py-2 text-sm"
                              placeholder="Tulis balasan…">{{ old('teks') }}</textarea>
                    <div class="flex items-center justify-between mt-2">
                        <span class="text-xs text-gray-500">Jendela 24 jam terbuka {{ $percakapan->windowHoursLeft() }} jam lagi.</span>
                        <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Kirim</button>
                    </div>
                </form>
            @else
                {{-- Kotak ketik sengaja TIDAK ditampilkan saat jendela tertutup: kalau
                     ditampilkan, admin mengetik panjang lalu ditolak, dan langsung
                     kembali membalas dari HP. --}}
                <div class="rounded border border-gray-300 bg-gray-50 px-3 py-3 text-sm text-gray-700">
                    <b>Jendela 24 jam tertutup.</b> Pesan bebas tidak bisa dikirim; hanya template berbayar
                    yang boleh keluar. Cara termurah membukanya kembali: pelanggan membalas lebih dulu.
                </div>
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------------- triase --}}
    <div class="space-y-3">
        <div class="bg-white rounded shadow p-3 space-y-3">
            <div>
                <div class="text-xs text-gray-500 mb-1">Bola di siapa</div>
                <form method="POST" action="{{ route('crm.inbox.antrean', $percakapan->id) }}" class="flex gap-2">
                    @csrf
                    <select name="queue_state" class="border rounded px-2 py-1.5 text-sm w-full">
                        @foreach(\App\Modules\CRM\Models\CrmConversation::QUEUE_LABELS as $key => $label)
                            <option value="{{ $key }}" @selected($percakapan->queue_state === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button class="border border-blue-600 text-blue-600 hover:bg-blue-50 px-3 py-1.5 rounded text-sm">Ubah</button>
                </form>
            </div>

            <div>
                <div class="text-xs text-gray-500 mb-1">Pemilik</div>
                <form method="POST" action="{{ route('crm.inbox.oper', $percakapan->id) }}" class="flex gap-2">
                    @csrf
                    <select name="owner_user_id" class="border rounded px-2 py-1.5 text-sm w-full">
                        <option value="">— belum dioper —</option>
                        @foreach($pemilikOpsi as $u)
                            <option value="{{ $u->id }}" @selected($percakapan->owner_user_id === $u->id)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                    <button class="border border-blue-600 text-blue-600 hover:bg-blue-50 px-3 py-1.5 rounded text-sm">Oper</button>
                </form>
            </div>

            <form method="POST" action="{{ route('crm.inbox.arsip', $percakapan->id) }}">
                @csrf
                <button class="w-full border border-gray-300 hover:bg-gray-50 px-3 py-1.5 rounded text-sm">
                    {{ $percakapan->status === 'aktif' ? 'Arsipkan' : 'Aktifkan lagi' }}
                </button>
            </form>
        </div>

        <div class="bg-white rounded shadow p-3">
            <div class="text-xs text-gray-500 mb-1">Catatan internal</div>
            <form method="POST" action="{{ route('crm.inbox.catatan', $percakapan->id) }}">
                @csrf
                <textarea name="notes" rows="5" class="border rounded w-full px-3 py-2 text-sm"
                          placeholder="Spesifikasi, kesepakatan, hal yang perlu diingat…">{{ $percakapan->notes }}</textarea>
                <button class="mt-2 border border-blue-600 text-blue-600 hover:bg-blue-50 px-3 py-1.5 rounded text-sm">Simpan Catatan</button>
            </form>
        </div>
    </div>
</div>
@endsection
