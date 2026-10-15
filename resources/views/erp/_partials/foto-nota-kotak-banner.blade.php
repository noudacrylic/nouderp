{{-- Pengingat di daftar dokumen: ada foto nota dari HP yang belum ditempel. --}}
@php $jumlahKotak = \App\Services\FotoNotaService::kotak()->count(); @endphp
@if ($jumlahKotak > 0)
    <a href="{{ route('nota-kotak.index') }}"
       class="flex items-center gap-2 bg-teal-50 border border-teal-200 text-teal-800 rounded px-3 py-2 mb-3 text-sm hover:bg-teal-100">
        📱 <b>{{ $jumlahKotak }}</b> foto nota dari HP belum ditempel ke dokumen.
        <span class="underline ml-auto text-xs">Lihat Kotak Nota</span>
    </a>
@endif
