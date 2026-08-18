{{--
    Daftar nama ikon yang dikenali etalase.

    Kolom "Ikon" menyimpan NAMA, bukan gambar: etalase menggambar ikon garis yang
    seragam dari nama itu. Nama yang tidak dikenal ditampilkan apa adanya — jadi
    emoji tetap bisa dipakai, hanya bentuknya berbeda-beda antar perangkat.
--}}
<div class="bg-gray-50 border border-gray-200 rounded p-3 text-xs text-gray-600">
    <span class="font-semibold">Nama ikon yang tersedia:</span>
    @foreach([
        'pabrik', 'laser', 'kotak', 'tag', 'truk', 'pin', 'koper', 'toko',
        'hadiah', 'pena', 'megafon', 'berlian', 'perisai', 'jam', 'kartu',
        'chat', 'bumi', 'centang',
    ] as $ikon)
        <code class="bg-white border rounded px-1.5 py-0.5 mr-1 inline-block mb-1">{{ $ikon }}</code>
    @endforeach
    <p class="mt-1 text-gray-400">
        Ketik salah satu nama di atas untuk mendapat ikon garis yang seragam. Diisi emoji juga boleh,
        tapi bentuk dan warnanya akan berbeda-beda di tiap perangkat.
    </p>
</div>
