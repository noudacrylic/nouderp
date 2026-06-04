{{--
    Tombol copy nomor (untuk inline di kolom number).
    Variabel:
      $value — string yang akan di-copy ke clipboard
--}}
<button type="button"
        class="copy-btn ml-1 text-gray-300 hover:text-blue-600 align-middle inline-flex items-center"
        data-copy="{{ $value }}"
        title="Salin {{ $value }}"
        onclick="event.stopPropagation()">
    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="9" y="9" width="11" height="11" rx="2"/>
        <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
    </svg>
</button>
