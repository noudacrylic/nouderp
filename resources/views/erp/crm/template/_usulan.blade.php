{{-- Satu kartu bunyi template. Dipisah karena dipakai DUA daftar (yang perlu
     diajukan ke Meta & yang khusus WAHA); kalau markupnya digandakan, keduanya
     cepat berbeda tanpa ada yang sadar. --}}
<div class="bg-white rounded shadow p-3">
    <div class="flex flex-wrap items-center gap-2 mb-1">
        <span class="font-mono text-sm font-semibold">{{ $u['nama'] }}</span>
        <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 text-xs">{{ $u['kategori'] }}</span>
        <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 text-xs">id</span>

        @if(! empty($u['hanya_waha']))
            <span class="px-2 py-0.5 rounded-full bg-violet-100 text-violet-700 text-xs">khusus WAHA</span>
        @elseif($wajibResmi)
            {{-- Yang WAJIB resmi: kalau templatenya belum disetujui, pesannya
                 tidak punya jalur cadangan sama sekali — ia gagal, bukan
                 dialihkan ke WAHA. --}}
            <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 text-xs">wajib jalur resmi</span>
        @endif
    </div>
    <p class="text-xs text-gray-500 mb-2">{{ $u['guna'] }}</p>
    <textarea readonly rows="3" onclick="this.select()"
              class="w-full border rounded px-3 py-2 text-sm bg-gray-50">{{ $u['body'] }}</textarea>

    @if(empty($u['hanya_waha']))
        {{-- Statusnya dibaca dari daftar yang benar-benar ada di vendor, bukan
             dari tebakan: tombol "Ajukan" yang tetap muncul untuk template yang
             sudah disetujui akan membuat orang mengajukannya dua kali, dan
             percobaan kedua ditolak karena namanya sudah terpakai. --}}
        @php $sudah = collect($daftar['templates'] ?? [])->firstWhere('name', $u['nama']); @endphp

        <div class="mt-2 flex items-center gap-2">
            @if($sudah)
                <span class="px-2 py-0.5 rounded-full text-xs
                    {{ $sudah['status'] === 'APPROVED' ? 'bg-green-100 text-green-700'
                       : ($sudah['status'] === 'REJECTED' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800') }}">
                    sudah diajukan — {{ $sudah['status'] }}
                </span>
                @if($sudah['status'] === 'REJECTED')
                    <span class="text-xs text-gray-500">Perbaiki lewat dasbor vendor; nama ini sudah terpakai.</span>
                @endif
            @else
                <form method="POST" action="{{ route('crm.template.ajukan') }}"
                      onsubmit="return confirm('Ajukan template &quot;{{ $u['nama'] }}&quot; ke Meta? Bunyinya tidak bisa diubah dari ERP setelah diajukan.')">
                    @csrf
                    <input type="hidden" name="nama" value="{{ $u['nama'] }}">
                    <button class="border border-emerald-600 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded text-xs">
                        Ajukan ke Meta
                    </button>
                </form>
                <span class="text-xs text-gray-500">Sekali diajukan, bunyinya hanya bisa diubah dari dasbor vendor.</span>
            @endif
        </div>
    @endif
</div>
