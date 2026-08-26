{{--
    Baris kecil "kapan angka ini dihitung" + tombol hitung ulang. Dipakai semua halaman
    Analisa yang angkanya disimpan (lihat App\Modules\Analysis\Support\AnalysisCache).

    Ada dua alasan baris ini wajib kelihatan, bukan disembunyikan sebagai detail teknis:

    1. Angka tersimpan tanpa keterangan waktu adalah angka yang tidak bisa dipercaya.
       Yang membaca halaman ini sedang menetapkan harga jual; ia berhak tahu apakah yang
       dilihatnya hasil hitungan barusan atau hitungan tiga jam lalu.

    2. Penyegaran otomatis selalu punya celah — perubahan yang tidak menyentuh kolom
       `updated_at` tidak tertangkap sidik jari data. Tombolnya adalah jalan keluar yang
       tidak perlu menunggu programmer.

    Waktunya diambil dari bagian PALING TUA yang dipakai halaman ini, bukan yang paling
    baru — lihat AnalysisCache::servedAt().
--}}
@php
    $analisaCache   = app(\App\Modules\Analysis\Support\AnalysisCache::class);
    $analisaDihitung = $analisaCache->servedAt();
@endphp

@if($analisaDihitung)
    <div class="flex items-center justify-end gap-2 mb-3 text-[11px] text-slate-400">
        <span>
            Angka dihitung {{ $analisaDihitung->diffForHumans() }}
            <span class="text-slate-300">·</span>
            dihitung ulang sendiri begitu ada data yang berubah
        </span>
        <form method="POST" action="{{ route('analisa.hitung-ulang') }}">
            @csrf
            <button type="submit"
                    class="border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-700 px-2.5 py-1 rounded-lg text-[11px] font-semibold">
                Hitung ulang
            </button>
        </form>
    </div>
@endif
