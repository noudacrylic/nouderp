{{-- Tab "Info" pada rail kanan: triase, kepemilikan, catatan internal. --}}
{{-- ---------------------------------------------------------------- triase --}}
<div class="space-y-3">
        <div class="border border-gray-200 rounded p-3 space-y-3">
            <div>
                <div class="text-xs text-gray-500 mb-1">Bola di siapa</div>
                <form method="POST" action="{{ route('crm.inbox.antrean', $terpilih->id) }}" class="flex gap-2">
                    @csrf
                    <select name="queue_state" class="border rounded px-2 py-1.5 text-sm w-full">
                        @foreach(\App\Modules\CRM\Models\CrmConversation::QUEUE_LABELS as $key => $label)
                            <option value="{{ $key }}" @selected($terpilih->queue_state === $key)>{{ $label }}</option>
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
