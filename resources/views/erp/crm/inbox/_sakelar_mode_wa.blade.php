{{-- Sakelar mode WhatsApp Web. Tombol tipis bergaris, bukan tombol padat: ini
     pengaturan tampilan yang ditekan sekali lalu dilupakan berminggu-minggu —
     ia tidak boleh terlihat sederajat dengan aksi kerja di dalam kolom. --}}
<form method="POST" action="{{ route('crm.inbox.mode-wa') }}" class="shrink-0">
    @csrf
    <button type="submit"
            class="px-2 py-1 rounded border text-xs {{ $modeWa
                ? 'border-sky-600 text-sky-700 bg-sky-50 hover:bg-sky-100'
                : 'border-gray-300 text-gray-600 bg-white hover:bg-gray-50' }}">
        {{ $modeWa ? 'Kembali ke tampilan chat penuh' : 'Mode WhatsApp Web' }}
    </button>
</form>
