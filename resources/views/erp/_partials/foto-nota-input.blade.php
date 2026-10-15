{{-- Input foto nota di form buat/edit dokumen. Form induknya WAJIB
     enctype="multipart/form-data". Berkas disimpan setelah dokumen tersimpan
     (FotoNotaService::simpanDariRequest). --}}
<div class="bg-white rounded shadow p-4 mb-4">
    <div class="flex flex-wrap items-center gap-2">
        <span class="font-semibold text-sm mr-1">📎 Foto Nota</span>
        <label class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm font-semibold">
            📷 Foto nota
            <input type="file" name="nota[]" accept="image/*" capture="environment" multiple data-foto-nota class="hidden">
        </label>
        <label class="cursor-pointer border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded text-sm">
            🖼️ Pilih file
            <input type="file" name="nota[]" accept="image/*,application/pdf" multiple data-foto-nota class="hidden">
        </label>
        <span data-foto-nota-info class="text-xs text-emerald-700"></span>
    </div>
    <p class="text-xs text-gray-400 mt-1.5">
        Opsional. Bisa juga ditambahkan nanti dari halaman detail, termasuk setelah POST.
        @error('nota.*') <span class="text-red-600">{{ $message }}</span> @enderror
        @error('nota') <span class="text-red-600">{{ $message }}</span> @enderror
    </p>
</div>

@once
    @push('scripts')
        <script src="{{ asset('js/foto-nota.js') }}?v={{ @filemtime(public_path('js/foto-nota.js')) }}"></script>
    @endpush
@endonce
