{{--
    Satu slot gambar beranda: pratinjau + pilih berkas baru + centang hapus.
    Parameter: $title, $field, $url, $note
--}}
<div class="bg-white rounded shadow p-4 space-y-3">
    <div class="text-xs font-semibold text-gray-600 uppercase tracking-wide">{{ $title }}</div>

    @if($url)
        <img src="{{ $url }}" alt="{{ $title }}" class="w-full max-w-md rounded border object-cover">
        <label class="flex items-center gap-2 text-sm text-red-600">
            <input type="checkbox" name="remove_{{ $field }}" value="1" class="w-4 h-4">
            Hapus gambar ini
        </label>
    @else
        <p class="text-xs text-gray-400 italic">Belum ada gambar.</p>
    @endif

    <div>
        <label class="block text-xs text-gray-500 mb-1">
            {{ $url ? 'Ganti dengan gambar baru' : 'Unggah gambar' }}
        </label>
        <input type="file" name="{{ $field }}" accept="image/*" class="text-sm w-full">
    </div>

    <p class="text-xs text-gray-400">{{ $note }}</p>
</div>
