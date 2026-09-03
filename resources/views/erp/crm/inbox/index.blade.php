@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Inbox CRM</h1>
    <a href="{{ route('crm.notifikasi.index') }}" class="border border-gray-300 hover:bg-gray-50 px-3 py-2 rounded text-sm">Notifikasi Pesanan</a>
</div>

@if($dryRun)
    <div class="mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
        <b>Mode aman menyala.</b> Balasan tetap tercatat di thread, tapi tidak benar-benar dikirim ke pelanggan.
    </div>
@endif

{{-- Antrean berbasis "bola di siapa" — Menunggu Kita sengaja paling depan. --}}
<div class="flex flex-wrap gap-2 mb-3 text-sm">
    @php $antreanAktif = request('antrean'); @endphp
    <a href="{{ request()->fullUrlWithQuery(['antrean' => null, 'page' => null]) }}"
       class="px-3 py-1.5 rounded border {{ $antreanAktif ? 'border-gray-300 bg-white hover:bg-gray-50' : 'border-blue-600 bg-blue-600 text-white' }}">
        Semua
    </a>
    @foreach(\App\Modules\CRM\Models\CrmConversation::QUEUE_LABELS as $key => $label)
        <a href="{{ request()->fullUrlWithQuery(['antrean' => $key, 'page' => null]) }}"
           class="px-3 py-1.5 rounded border {{ $antreanAktif === $key ? 'border-blue-600 bg-blue-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50' }}">
            {{ $label }}
            <span class="ml-1 text-xs {{ $antreanAktif === $key ? 'text-blue-100' : 'text-gray-500' }}">{{ $jumlah[$key] ?? 0 }}</span>
        </a>
    @endforeach
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <input type="hidden" name="antrean" value="{{ request('antrean') }}">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nomor atau nama..."
               class="border rounded px-3 py-1.5 w-56">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Pemilik</label>
        <select name="pemilik" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="belum" @selected(request('pemilik')==='belum')>Belum dioper</option>
            @foreach($pemilikOpsi as $u)
                <option value="{{ $u->id }}" @selected(request('pemilik')==(string) $u->id)>{{ $u->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="aktif" @selected(request('status','aktif')==='aktif')>Aktif</option>
            <option value="arsip" @selected(request('status')==='arsip')>Arsip</option>
        </select>
    </div>
    @include('erp._partials.per-page-select')
    <button class="bg-blue-600 text-white px-3 py-1.5 rounded">Terapkan</button>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Kontak</th>
                <th class="px-3 py-2 text-left">Pesan Terakhir</th>
                <th class="px-3 py-2 text-left">Antrean</th>
                <th class="px-3 py-2 text-left">Pemilik</th>
                <th class="px-3 py-2 text-center">Jendela 24 Jam</th>
            </tr>
        </thead>
        <tbody>
            @forelse($percakapan as $p)
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('crm.inbox.show', $p->id) }}">
                    <td class="px-3 py-2">
                        <div class="font-medium">{{ $p->customer->name ?? $p->display_name ?? $p->contact_key }}</div>
                        <div class="text-xs text-gray-500">
                            {{ $p->contact_key }}
                            @unless($p->customer_id)
                                <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-600 uppercase"
                                      title="Belum tertaut pelanggan mana pun di ERP">Lead</span>
                            @endunless
                        </div>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        {{ $p->last_message_at?->diffForHumans() ?? '-' }}
                        @if($p->unread_count > 0)
                            <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-red-100 text-red-700">{{ $p->unread_count }} baru</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        @php
                            $qCls = match($p->queue_state) {
                                \App\Modules\CRM\Models\CrmConversation::QUEUE_KITA      => 'bg-orange-100 text-orange-700',
                                \App\Modules\CRM\Models\CrmConversation::QUEUE_PELANGGAN => 'bg-blue-100 text-blue-700',
                                \App\Modules\CRM\Models\CrmConversation::QUEUE_DESAIN    => 'bg-purple-100 text-purple-700',
                                default                                                  => 'bg-gray-100 text-gray-600',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $qCls }}">
                            {{ \App\Modules\CRM\Models\CrmConversation::QUEUE_LABELS[$p->queue_state] ?? $p->queue_state }}
                        </span>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $p->owner->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        @if($p->windowIsOpen())
                            <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700">Terbuka {{ $p->windowHoursLeft() }} jam</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-500" title="Hanya template berbayar yang bisa dikirim">Tertutup</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Belum ada percakapan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $percakapan->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
