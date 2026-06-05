{{--
    Live-search input untuk index purchasing.
    Variabel:
      $placeholder (opsional) — default: "Cari nomor / supplier..."
      $name (opsional) — default: 'q'
--}}
@php
    $name = $name ?? 'q';
    $placeholder = $placeholder ?? 'Cari nomor atau nama pemasok...';
@endphp
<div class="flex-1 min-w-[260px]">
    <label class="block text-xs text-gray-500 mb-1">Cari</label>
    <div class="relative">
        <svg class="absolute left-2 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none"
             xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="7"/>
            <path d="M21 21l-4.3-4.3"/>
        </svg>
        <input type="text"
               name="{{ $name }}"
               value="{{ request($name) }}"
               placeholder="{{ $placeholder }}"
               autocomplete="off"
               class="search-live border rounded pl-7 pr-2 py-1.5 w-full text-sm">
    </div>
</div>
