@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4 max-w-6xl">
    <div class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">Kotak Nota</h1>
        <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
            Foto nota yang dikirim dari HP (PWA <b>/cs</b> → menu <b>Nota</b>) dan belum ditempel ke dokumen.
            Untuk menempel: buka Faktur Pembelian / Pembayaran Supplier / Pengeluaran-nya, lalu tekan
            <b>📱 Ambil dari HP</b> di kartu Foto Nota.
        </p>
    </div>

    @if ($kotak->isEmpty())
        <div class="bg-white rounded-2xl border border-slate-200 p-8 text-center text-slate-400">
            Kotak kosong — semua foto dari HP sudah ditempel. 👍
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-4">
            @foreach ($kotak as $k)
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                    <a href="{{ route('nota-kotak.gambar', $k->id) }}" target="_blank" rel="noopener" class="block bg-slate-50">
                        @if ($k->isImage())
                            <img src="{{ route('nota-kotak.gambar', $k->id) }}" alt="Foto nota" loading="lazy" class="w-full h-40 object-cover">
                        @else
                            <span class="flex items-center justify-center h-40 text-4xl">📄</span>
                        @endif
                    </a>
                    <div class="p-2.5 text-xs text-slate-500 flex-1">
                        <div>{{ $k->created_at->format('d/m/Y H:i') }} · {{ $k->uploader->name ?? '—' }}</div>
                        @if ($k->catatan)
                            <div class="text-sm font-semibold text-slate-800 mt-0.5">{{ $k->catatan }}</div>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('nota-kotak.destroy', $k->id) }}"
                          onsubmit="return confirm('Hapus foto ini dari kotak? (diarsipkan)')" class="border-t border-slate-100">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full text-xs text-red-600 hover:bg-red-50 py-1.5">Hapus</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
