{{-- Kolom KIRI: antrean, filter, dan daftar percakapan.
     Bentuknya daftar (bukan tabel) karena lebarnya sempit — tabel di kolom
     sesempit ini memaksa gulir mendatar dan tak ada yang mau memakainya. --}}
<div class="flex flex-col h-full min-h-0 bg-white border border-gray-200 rounded-lg overflow-hidden">

    <div class="shrink-0 px-3 pt-3 pb-2 border-b border-gray-200 bg-gray-50">

    <a href="{{ route('crm.template.baru') }}"
       class="block mb-2 text-center bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm font-medium">
        Chat Baru
    </a>

    {{-- Antrean berbasis "bola di siapa" — Menunggu Kita sengaja paling depan. --}}
    @php $antreanAktif = request('antrean'); @endphp
    <div class="flex flex-wrap gap-1 mb-2 text-xs">
        <a href="{{ request()->fullUrlWithQuery(['antrean' => null, 'page' => null]) }}"
           class="px-2 py-1 rounded border {{ $antreanAktif ? 'border-gray-300 bg-white hover:bg-gray-50' : 'border-emerald-600 bg-emerald-600 text-white' }}">
            Semua
        </a>
        @foreach(\App\Modules\CRM\Models\CrmConversation::QUEUE_LABELS as $key => $label)
            <a href="{{ request()->fullUrlWithQuery(['antrean' => $key, 'page' => null]) }}"
               class="px-2 py-1 rounded border {{ $antreanAktif === $key ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50' }}">
                {{ $label }}
                <span class="ml-0.5 {{ $antreanAktif === $key ? 'text-emerald-100' : 'text-gray-500' }}">{{ $jumlah[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    @if($dibatasiKeSaya)
        <div class="mb-2 rounded border border-gray-300 bg-gray-50 px-2 py-1.5 text-xs text-gray-700">
            Chat yang Anda pegang ·
            <a href="{{ request()->fullUrlWithQuery(['pemilik' => 'semua', 'page' => null]) }}" class="text-emerald-700 hover:underline">semua agen</a>
            @if($belumDioper > 0)
                ·
                <a href="{{ request()->fullUrlWithQuery(['pemilik' => 'belum', 'page' => null]) }}" class="text-emerald-700 hover:underline">
                    {{ $belumDioper }} belum dioper
                </a>
            @endif
        </div>
    @elseif($belumDioper > 0 && request('pemilik') !== 'belum')
        <div class="mb-2 text-xs">
            <a href="{{ request()->fullUrlWithQuery(['pemilik' => 'belum', 'page' => null]) }}"
               class="text-emerald-700 hover:underline">{{ $belumDioper }} chat belum dioper</a>
        </div>
    @endif

    <form method="GET" class="mb-2 space-y-2">
        <input type="hidden" name="antrean" value="{{ request('antrean') }}">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari nomor atau nama…"
               class="border rounded px-2 py-1.5 text-sm w-full">
        <div class="flex gap-2">
            <select name="pemilik" class="filter-auto border rounded px-2 py-1.5 text-xs w-full">
                <option value="">Pemilik: bawaan</option>
                <option value="semua" @selected(request('pemilik')==='semua')>Semua agen</option>
                <option value="belum" @selected(request('pemilik')==='belum')>Belum dioper</option>
                @foreach($pemilikOpsi as $u)
                    <option value="{{ $u->id }}" @selected(request('pemilik')==(string) $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
            <select name="status" class="filter-auto border rounded px-2 py-1.5 text-xs">
                <option value="aktif" @selected(request('status','aktif')==='aktif')>Aktif</option>
                <option value="arsip" @selected(request('status')==='arsip')>Arsip</option>
            </select>
        </div>
    </form>

    </div>

    <div class="flex-1 min-h-0 overflow-y-auto divide-y divide-gray-100">
        @forelse($percakapan as $p)
            @php $aktif = $terpilih && $terpilih->id === $p->id; @endphp
            <a href="{{ route('crm.inbox.show', $p->id) }}"
               class="block px-3 py-2 hover:bg-emerald-50 {{ $aktif ? 'bg-emerald-50 border-l-4 border-emerald-600' : '' }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-medium truncate">{{ $p->customer->name ?? $p->display_name ?? $p->contact_key }}</div>
                        <div class="text-xs text-gray-500 truncate">
                            {{ $p->contact_key }}
                            @unless($p->customer_id)
                                <span class="ml-1 px-1 rounded text-[10px] font-semibold bg-slate-100 text-slate-600 uppercase"
                                      title="Belum tertaut pelanggan mana pun di ERP">Lead</span>
                            @endunless
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-[11px] text-gray-400">{{ $p->last_message_at?->diffForHumans() ?? '—' }}</div>
                        @if($p->unread_count > 0)
                            <span class="inline-block mt-1 px-1.5 rounded-full text-[10px] font-semibold bg-red-100 text-red-700">{{ $p->unread_count }}</span>
                        @endif
                    </div>
                </div>
                <div class="mt-1 flex flex-wrap items-center gap-1">
                    @php
                        $qCls = match($p->queue_state) {
                            \App\Modules\CRM\Models\CrmConversation::QUEUE_KITA      => 'bg-orange-100 text-orange-700',
                            \App\Modules\CRM\Models\CrmConversation::QUEUE_PELANGGAN => 'bg-blue-100 text-blue-700',
                            \App\Modules\CRM\Models\CrmConversation::QUEUE_DESAIN    => 'bg-purple-100 text-purple-700',
                            default                                                  => 'bg-gray-100 text-gray-600',
                        };
                    @endphp
                    <span class="px-1.5 py-0.5 rounded text-[10px] {{ $qCls }}">
                        {{ \App\Modules\CRM\Models\CrmConversation::QUEUE_LABELS[$p->queue_state] ?? $p->queue_state }}
                    </span>
                    @if($p->owner)
                        <span class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 text-gray-600">{{ $p->owner->name }}</span>
                    @endif
                    @unless($p->windowIsOpen())
                        <span class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 text-gray-500"
                              title="Hanya template berbayar yang bisa dikirim">jendela tutup</span>
                    @endunless
                </div>
            </a>
        @empty
            <p class="px-3 py-6 text-center text-sm text-gray-500">Belum ada percakapan.</p>
        @endforelse
    </div>

    <div class="shrink-0 px-3 py-2 border-t border-gray-200 bg-gray-50 text-xs">{{ $percakapan->links() }}</div>
</div>
