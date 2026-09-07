{{-- Tab "Info" pada rail kanan: triase, kepemilikan, catatan internal. --}}
{{-- ---------------------------------------------------------------- triase --}}
<div class="space-y-3">
        <div class="border border-gray-200 rounded p-3 space-y-3">
            <div>
                <div class="text-xs text-gray-500 mb-1">Label
                    <a href="{{ route('crm.label.index') }}" class="ml-1 text-emerald-700 hover:underline">atur</a>
                </div>
                <form method="POST" action="{{ route('crm.inbox.antrean', $terpilih->id) }}" class="flex gap-2">
                    @csrf
                    <select name="queue_state" class="border rounded px-2 py-1.5 text-sm w-full">
                        @foreach(\App\Modules\CRM\Models\CrmLabel::terpakai() as $l)
                            <option value="{{ $l->kode }}" @selected($terpilih->queue_state === $l->kode)>{{ $l->nama }}</option>
                        @endforeach
                    </select>
                    <button class="border border-emerald-600 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded text-sm">Ubah</button>
                </form>
            </div>

            <div>
                <div class="text-xs text-gray-500 mb-1">Pemilik</div>
                <form method="POST" action="{{ route('crm.inbox.oper', $terpilih->id) }}" class="flex gap-2">
                    @csrf
                    <select name="owner_user_id" class="border rounded px-2 py-1.5 text-sm w-full">
                        <option value="">— belum dioper —</option>
                        @foreach($pemilikOpsi as $u)
                            <option value="{{ $u->id }}" @selected($terpilih->owner_user_id === $u->id)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                    <button class="border border-emerald-600 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded text-sm">Oper</button>
                </form>
            </div>

            @php $lampiranTertunda = \App\Modules\CRM\Models\CrmAttachment::belumTerunduh()->count(); @endphp
            @if($lampiranTertunda > 0)
                <form method="POST" action="{{ route('crm.lampiran.unduh') }}">
                    @csrf
                    <button class="w-full border border-amber-500 text-amber-700 hover:bg-amber-50 px-3 py-1.5 rounded text-sm">
                        Unduh {{ $lampiranTertunda }} lampiran tertunda
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('crm.inbox.arsip', $terpilih->id) }}">
                @csrf
                <button class="w-full border border-gray-300 hover:bg-gray-50 px-3 py-1.5 rounded text-sm">
                    {{ $terpilih->status === 'aktif' ? 'Arsipkan' : 'Aktifkan lagi' }}
                </button>
            </form>
        </div>

        <div class="border border-gray-200 rounded p-3">
            <div class="text-xs text-gray-500 mb-1">Catatan internal</div>
            <form method="POST" action="{{ route('crm.inbox.catatan', $terpilih->id) }}">
                @csrf
                <textarea name="notes" rows="5" class="border rounded w-full px-3 py-2 text-sm"
                          placeholder="Spesifikasi, kesepakatan, hal yang perlu diingat…">{{ $terpilih->notes }}</textarea>
                <button class="mt-2 border border-emerald-600 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded text-sm">Simpan Catatan</button>
            </form>
        </div>
    </div>
