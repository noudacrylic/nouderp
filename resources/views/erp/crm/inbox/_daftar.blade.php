{{-- Kolom KIRI: antrean, filter, dan daftar percakapan.
     Bentuknya daftar (bukan tabel) karena lebarnya sempit — tabel di kolom
     sesempit ini memaksa gulir mendatar dan tak ada yang mau memakainya. --}}
<div class="flex flex-col h-full min-h-0 bg-white border border-gray-200 rounded-lg overflow-hidden">

    {{-- Kepala kolom: TIGA baris, tidak lebih.

         Urutannya menirukan cara pertanyaan datang: "chat siapa" (baris 1),
         "chat yang mana" (baris 2, pencarian), lalu "yang bagaimana" (baris 3,
         label). Sebelumnya kepala ini enam baris dan memakan sepertiga tinggi
         layar — di kolom chat, tinggi yang dimakan penyaring adalah percakapan
         yang tidak terlihat. --}}
    @php
        $akuId       = auth()->id();
        $pemilikKini = request('pemilik');
        $milikSaya   = $pemilikKini === (string) $akuId;
        $semuaOrang  = $pemilikKini === 'semua';
        $agenLain    = $pemilikKini && ! $milikSaya && ! $semuaOrang;   // id agen lain / 'belum'
        $tabAktif    = 'px-2 py-1 rounded border border-emerald-600 bg-emerald-600 text-white';
        $tabDiam     = 'px-2 py-1 rounded border border-gray-300 bg-white hover:bg-gray-50';
    @endphp

    <div class="shrink-0 px-2 pt-2 pb-2 border-b border-gray-200 bg-gray-50 space-y-1.5">

    {{-- ---------------------------------------------------- 1. chat siapa --}}
    <div class="grid grid-cols-3 gap-1 text-xs">
        <a href="{{ request()->fullUrlWithQuery(['pemilik' => 'semua', 'page' => null]) }}"
           class="text-center truncate {{ $semuaOrang ? $tabAktif : $tabDiam }}"
           title="Semua chat, milik siapa pun">Semua</a>

        <a href="{{ request()->fullUrlWithQuery(['pemilik' => $akuId, 'page' => null]) }}"
           class="text-center truncate {{ $milikSaya || $dibatasiKeSaya ? $tabAktif : $tabDiam }}"
           title="Chat yang saya pegang">Milik Saya</a>

        @if($lihatSemua ?? false)
            {{-- Dropdown agen lain hanya untuk super admin: bagi agen biasa,
                 daftar nama rekan tidak menambah apa pun selain kebingungan —
                 yang ia butuh cuma miliknya dan yang belum dipegang siapa pun. --}}
            <form method="GET" class="contents">
                <input type="hidden" name="antrean" value="{{ request('antrean') }}">
                <input type="hidden" name="belum_dibaca" value="{{ request('belum_dibaca') }}">
                <input type="hidden" name="status" value="{{ request('status') }}">
                <input type="hidden" name="search" value="{{ request('search') }}">
                <select name="pemilik" onchange="this.form.submit()"
                        class="border rounded px-1 py-1 text-xs w-full {{ $agenLain ? 'border-emerald-600 text-emerald-700 font-medium' : 'border-gray-300' }}">
                    <option value="">Agen lain…</option>
                    <option value="belum" @selected($pemilikKini === 'belum')>Belum dioper ({{ $belumDioper }})</option>
                    @foreach($pemilikOpsi as $u)
                        @continue($u->id === $akuId)
                        <option value="{{ $u->id }}" @selected($pemilikKini === (string) $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </form>
        @else
            <a href="{{ request()->fullUrlWithQuery(['pemilik' => 'belum', 'page' => null]) }}"
               class="text-center truncate {{ $pemilikKini === 'belum' ? $tabAktif : $tabDiam }}"
               title="Chat yang belum dipegang siapa pun">Belum dioper {{ $belumDioper }}</a>
        @endif
    </div>

    {{-- ------------------------------------------------------ 2. pencarian --}}
    <form method="GET" class="flex gap-1">
        <input type="hidden" name="antrean" value="{{ request('antrean') }}">
        <input type="hidden" name="belum_dibaca" value="{{ request('belum_dibaca') }}">
        <input type="hidden" name="status" value="{{ request('status') }}">
        <input type="hidden" name="pemilik" value="{{ request('pemilik') }}">
        <input type="text" name="search" value="{{ request('search') }}"
               placeholder="Cari nama, nomor, atau nomor pesanan…"
               class="border rounded px-2 py-1.5 text-sm w-full">
        @if(request('search'))
            <a href="{{ request()->fullUrlWithQuery(['search' => null, 'page' => null]) }}"
               class="shrink-0 border border-gray-300 rounded px-2 py-1.5 text-xs text-gray-500 hover:bg-gray-100"
               title="Hapus pencarian">✕</a>
        @endif
        <a href="{{ route('crm.template.baru') }}"
           class="shrink-0 bg-emerald-600 hover:bg-emerald-700 text-white rounded px-2.5 py-1.5 text-sm font-medium leading-5"
           title="Mulai chat baru">＋</a>
    </form>

    {{-- ---------------------------------------------------------- 3. label --}}
    @php
        $antreanAktif     = request('antrean');
        $belumDibacaAktif = request()->boolean('belum_dibaca');
        $semuaAktif       = ! $antreanAktif && ! $belumDibacaAktif;
        $chip             = 'px-1.5 py-0.5 rounded border text-[11px] leading-4';
    @endphp
    <div class="flex flex-wrap gap-1">
        <a href="{{ request()->fullUrlWithQuery(['antrean' => null, 'belum_dibaca' => null, 'page' => null]) }}"
           class="{{ $chip }} {{ $semuaAktif ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50' }}">
            Semua <span class="{{ $semuaAktif ? 'text-emerald-100' : 'text-gray-500' }}">{{ $jumlahSemua }}</span>
        </a>

        {{-- "Belum dibaca" berdiri di sebelah "Semua", bukan di tengah label:
             ini bukan keadaan chat, melainkan pekerjaan yang belum disentuh. --}}
        <a href="{{ request()->fullUrlWithQuery(['belum_dibaca' => 1, 'antrean' => null, 'page' => null]) }}"
           class="{{ $chip }} {{ $belumDibacaAktif ? 'border-red-600 bg-red-600 text-white' : ($jumlahBelumDibaca > 0 ? 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100' : 'border-gray-300 bg-white hover:bg-gray-50') }}">
            Belum dibaca <span class="{{ $belumDibacaAktif ? 'text-red-100' : '' }}">{{ $jumlahBelumDibaca }}</span>
        </a>

        @foreach($labelOpsi as $l)
            <a href="{{ request()->fullUrlWithQuery(['antrean' => $l->kode, 'belum_dibaca' => null, 'page' => null]) }}"
               class="{{ $chip }} {{ $antreanAktif === $l->kode ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50' }}">
                {{ $l->nama }}
                <span class="{{ $antreanAktif === $l->kode ? 'text-emerald-100' : 'text-gray-500' }}">{{ $jumlah[$l->kode] ?? 0 }}</span>
            </a>
        @endforeach

        {{-- Arsip jadi chip, bukan dropdown sendiri: ia dipakai sesekali dan
             tidak pantas menempati satu baris penuh selamanya. --}}
        @php $diArsip = request('status') === 'arsip'; @endphp
        <a href="{{ request()->fullUrlWithQuery(['status' => $diArsip ? null : 'arsip', 'page' => null]) }}"
           class="{{ $chip }} {{ $diArsip ? 'border-gray-700 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50' }}">
            Arsip
        </a>
    </div>

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
                    <span class="px-1.5 py-0.5 rounded text-[10px] {{ \App\Modules\CRM\Models\CrmLabel::kelas($p->queue_state) }}">
                        {{ \App\Modules\CRM\Models\CrmLabel::nama($p->queue_state) }}
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
